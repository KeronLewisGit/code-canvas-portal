<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrections to what a client owes, recorded alongside payments so every balance, statement and report agrees:
 * - credit note: reduces an invoice's balance (discount, write-off, goodwill); never counted as money received.
 * - refund: money paid back from a client's credit; recorded as a negative payment.
 * - late fee: a separate invoice for one month's fee on an overdue balance, per the contract terms.
 */
class Adjustments
{
    public function creditNote(Invoice $invoice, float $amount, string $reason, User $by): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $by) {
            $locked = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($locked->status, ['sent', 'partial'])) {
                throw ValidationException::withMessages(['amount' => 'Credit notes apply to sent or partly paid invoices.']);
            }
            if ($amount > $locked->balance() + 0.005) {
                throw ValidationException::withMessages(['amount' => 'A credit note cannot be more than the '.money($locked->balance(), $locked->currency).' still owed.']);
            }
            $credit = $this->record($locked->client_id, $locked->contract_id, $locked->id, $amount, $locked->currency, 'credit_note', $reason, $by);
            Audit::log($locked, 'credit_note', ['amount' => $amount, 'reason' => $reason]);

            return $credit;
        });
    }

    /** What can be refunded on a contract: unapplied contract credit plus anything paid beyond an invoice's total. */
    public static function refundableOnContract(Contract $contract): float
    {
        $overpaid = $contract->invoices()->whereNotIn('status', ['draft', 'cancelled'])->get()->sum(fn (Invoice $i) => max(0, $i->directPaidAmount() - (float) $i->total));

        return round($contract->unallocatedCredit() + $overpaid, 2);
    }

    /** What can be refunded on an invoice that is not part of a contract: anything paid beyond its total. */
    public static function refundableOnInvoice(Invoice $invoice): float
    {
        return $invoice->contract_id ? 0.0 : round(max(0, $invoice->directPaidAmount() - (float) $invoice->total), 2);
    }

    public function refund(Contract|Invoice $target, float $amount, string $how, ?string $note, User $by): Payment
    {
        return DB::transaction(function () use ($target, $amount, $how, $note, $by) {
            $target::query()->lockForUpdate()->find($target->id);
            $available = $target instanceof Contract ? self::refundableOnContract($target) : self::refundableOnInvoice($target);
            if ($amount > $available + 0.005) {
                throw ValidationException::withMessages(['refund_amount' => 'Only '.money($available, $target->currency).' is available to refund.']);
            }
            [$contractId, $invoiceId] = $target instanceof Contract ? [$target->id, null] : [$target->contract_id, $target->id];
            $refund = $this->record($target->client_id, $contractId, $invoiceId, -$amount, $target->currency, 'refund', trim('Paid back by '.$how.'. '.($note ?? '')), $by);
            Audit::log($target, 'refund', ['amount' => $amount, 'how' => $how]);

            return $refund;
        });
    }

    /** One month's late fee on an overdue invoice, as its own invoice. At most once every 30 days per invoice. */
    public function lateFee(Invoice $invoice, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $by) {
            $locked = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $fee = self::lateFeeFor($locked);
            if ($fee === null) {
                throw ValidationException::withMessages(['late_fee' => 'A late fee applies only to an overdue balance, at most once every 30 days.']);
            }
            $pct = (float) Setting::get('late_fee_monthly_percent');
            $feeInvoice = Invoice::create([
                'client_id' => $locked->client_id, 'contract_id' => $locked->contract_id, 'kind' => 'late_fee',
                'title' => 'Late payment fee on '.$locked->reference,
                'issue_date' => now(), 'due_date' => now()->addDays(7), 'currency' => $locked->currency, 'tax_rate' => 0,
                'status' => 'sent', 'sent_at' => now(), 'notes' => 'Charged under the late payment terms of your agreement.',
            ]);
            $feeInvoice->syncItems([['description' => 'Late payment fee: '.rtrim(rtrim(number_format($pct, 2), '0'), '.').'% of '.money($locked->balance(), $locked->currency).' overdue on '.$locked->reference, 'quantity' => 1, 'unit_price' => $fee]]);
            Audit::log($locked, 'late_fee_charged', ['fee_invoice' => $feeInvoice->reference, 'amount' => $fee, 'by' => $by->name]);

            return $feeInvoice->refresh();
        });
    }

    /** The fee that could be charged now, or null (not overdue, no rate set, a fee invoice itself, or charged in the last 30 days). */
    public static function lateFeeFor(Invoice $invoice): ?float
    {
        $pct = (float) Setting::get('late_fee_monthly_percent');
        if ($pct <= 0 || $invoice->kind === 'late_fee' || $invoice->effectiveStatus() !== 'overdue') {
            return null;
        }
        $recent = $invoice->activity()->where('action', 'late_fee_charged')->where('created_at', '>', now()->subDays(30))->exists();
        $fee = round($invoice->balance() * $pct / 100, 2);

        return $recent || $fee <= 0 ? null : $fee;
    }

    private function record(int $clientId, ?int $contractId, ?int $invoiceId, float $amount, string $currency, string $method, string $note, User $by): Payment
    {
        $payment = Payment::create([
            'client_id' => $clientId, 'contract_id' => $contractId, 'invoice_id' => $invoiceId, 'amount' => $amount, 'currency' => $currency,
            'method' => $method, 'paid_on' => now(), 'note' => $note, 'status' => 'verified',
            'submitted_by' => $by->id, 'verified_by' => $by->id, 'verified_at' => now(),
        ]);
        $payment->refreshDocuments();

        return $payment;
    }
}
