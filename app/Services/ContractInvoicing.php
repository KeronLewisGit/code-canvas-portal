<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Billing that follows from a contract. Once a contract is signed and live, its first invoice is issued automatically:
 * - one-off with a deposit: a deposit invoice for that percentage; the balance is invoiced later by an admin.
 * - one-off with no deposit (0% or 100%): one invoice for the full price.
 * - recurring: an invoice for the first billing period.
 * The deposit is expected, not enforced: nothing is blocked while it is unpaid.
 */
class ContractInvoicing
{
    public const KINDS = ['standard' => 'Invoice', 'deposit' => 'Deposit', 'balance' => 'Balance', 'cycle' => 'Billing period'];

    /** The contract's own deposit, or the Settings default. */
    public static function depositPercent(Contract $contract): float
    {
        return (float) ($contract->deposit_percent ?? Setting::get('deposit_percent'));
    }

    /** True when the one-off contract is billed as a deposit then a balance. */
    public static function takesDeposit(Contract $contract): bool
    {
        $pct = self::depositPercent($contract);

        return ! $contract->isRecurring() && $pct > 0 && $pct < 100;
    }

    /**
     * Issue the first invoice for a signed, live contract and return it; null when it is not ready yet or already
     * has an invoice (an admin may have made one by hand). Safe to call from every place a contract can become signed.
     */
    public function issueFirstInvoice(Contract $contract): ?Invoice
    {
        return DB::transaction(function () use ($contract) {
            $c = Contract::lockForUpdate()->findOrFail($contract->id);
            if (! $c->signed_at || ! in_array($c->status, ['approved', 'active']) || $c->invoices()->where('status', '!=', 'cancelled')->exists()) {
                return null;
            }
            $full = (float) $c->amount;
            [$kind, $amount, $line] = match (true) {
                $c->isRecurring() => ['cycle', $full, 'First '.$c->cycle()['per'].': '.$c->title],
                self::takesDeposit($c) => ['deposit', round($full * self::depositPercent($c) / 100, 2), rtrim(rtrim(number_format(self::depositPercent($c), 2), '0'), '.').'% deposit: '.$c->title],
                default => ['standard', $full, $c->title],
            };
            if ($amount <= 0) {
                return null;
            }
            $invoice = $this->create($c, $kind, $line, $amount);
            Audit::log($c, 'invoice_generated', ['invoice' => $invoice->reference, 'kind' => $kind, 'amount' => $amount]);

            return $invoice;
        });
    }

    /** What is left to bill on a one-off contract after earlier invoices: the figure for the balance invoice. */
    public static function balanceToInvoice(Contract $contract): float
    {
        return $contract->isRecurring() ? (float) $contract->amount : (float) ($contract->finance()->remainingValue() ?? 0);
    }

    /** Deposit invoice progress for the contract pages: null when this contract has no deposit invoice. */
    public static function depositStatus(Contract $contract): ?array
    {
        $deposit = $contract->invoices->firstWhere('kind', 'deposit');
        if (! $deposit || $deposit->status === 'cancelled') {
            return null;
        }

        return ['invoice' => $deposit, 'paid' => $deposit->status === 'paid', 'amount' => (float) $deposit->total, 'balance' => $deposit->balance()];
    }

    private function create(Contract $c, string $kind, string $line, float $amount): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $c->client_id,
            'contract_id' => $c->id,
            'quotation_id' => Quotation::where('contract_id', $c->id)->value('id'),
            'kind' => $kind,
            'title' => $line,
            'issue_date' => now(),
            'due_date' => now()->addDays((int) $c->payment_terms_days),
            'currency' => $c->currency,
            'tax_rate' => 0, // the contract price already includes any tax from the quotation
            'status' => 'sent',
            'sent_at' => now(),
            'notes' => config('company.document_terms'),
        ]);
        $invoice->syncItems([['description' => $line.' ('.$c->reference.')', 'quantity' => 1, 'unit_price' => $amount]]);

        return $invoice->refresh();
    }
}
