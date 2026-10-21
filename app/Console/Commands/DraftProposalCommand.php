<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\QuotationWorkflow;
use App\Support\Audit;
use App\Support\Reference;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Puts a prepared proposal on a client's profile: an optional draft quotation, a draft contract carrying the agreed
 * special terms, and any earlier invoices that were settled outside the portal (with their payments and receipts). The earlier
 * invoices sit on the contract; one with a 'period_start' is the invoice for that billing period, so it is not billed again.
 * Nothing is emailed and the client sees neither the quotation nor the contract until you send them. Safe on production.
 */
class DraftProposalCommand extends Command
{
    protected $signature = 'portal:draft-proposal {proposal : Name of a file in database/proposals, e.g. mbe-cayman} {--client= : Part of the client\'s company name, if different from the proposal file} {--create-client : Create the client when none matches} {--email= : Contact email, needed with --create-client}';

    protected $description = 'Draft a contract (and quotation) for a client from a proposal file, and record earlier paid invoices, without sending anything';

    public function handle(QuotationWorkflow $workflow): int
    {
        $file = database_path('proposals/'.basename((string) $this->argument('proposal')).'.php');
        if (! is_file($file)) {
            $this->error('No proposal file at '.$file);

            return self::FAILURE;
        }
        /** @var array<string, mixed> $proposal */
        $proposal = require $file;

        $client = $this->client($proposal['client']);
        if (! $client) {
            return self::FAILURE;
        }

        // A cancelled (withdrawn) draft of the same proposal does not count: it can be drafted again.
        $title = $proposal['quotation']['title'] ?? $proposal['contract']['title'];
        $exists = isset($proposal['quotation'])
            ? Quotation::where('client_id', $client->id)->where('title', $title)->where('status', '!=', 'cancelled')->exists()
            : Contract::where('client_id', $client->id)->where('title', $title)->where('status', '!=', 'cancelled')->exists();
        if ($exists) {
            $this->warn($client->company.' already has "'.$title.'". Nothing was changed.');

            return self::FAILURE;
        }
        if (($problem = $this->historyProblem($client, $proposal['history'] ?? [])) !== null) {
            $this->error($problem.' Nothing was changed.');

            return self::FAILURE;
        }

        [$quotation, $contract, $imported] = DB::transaction(function () use ($client, $proposal, $workflow) {
            $quotation = isset($proposal['quotation']) ? $this->createQuotation($client, $proposal['quotation']) : null;
            $contract = $quotation ? $workflow->createContract($quotation->fresh()) : $this->createContract($client, $proposal['contract']);
            $this->applyContractDetails($contract, $proposal['contract']);
            Audit::log($contract, 'special_terms_added', ['from_proposal' => $this->argument('proposal')]);

            return [$quotation, $contract, $this->importHistory($client, $contract, $proposal['history'] ?? [])];
        });

        $this->info('Drafted for '.$client->company.':');
        $quotation && $this->line('  Quotation '.$quotation->reference.' ('.money($quotation->total, $quotation->currency).' per '.$contract->cycle()['per'].'), draft, not sent');
        $this->line('  Contract  '.$contract->reference.', draft, not sent for signature');
        foreach ($imported as [$invoice, $payment]) {
            $period = $invoice->period_start ? ', '.$contract->cycle()['per'].' from '.$invoice->period_start->format('j M Y') : '';
            $this->line('  '.$invoice->reference.' '.money($invoice->total, $invoice->currency).' recorded as paid'.$period.', receipt '.$payment->receipt_number.' (not emailed)');
        }
        $this->line('Review it at '.route('admin.contracts.show', $contract).', check the start date, then use Send for signature.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $q */
    private function createQuotation(Client $client, array $q): Quotation
    {
        $quotation = Quotation::create([
            'client_id' => $client->id, 'title' => $q['title'], 'issue_date' => now(), 'valid_until' => now()->addDays($q['valid_days']),
            'currency' => $q['currency'], 'tax_rate' => config('company.tax_rate'), 'status' => 'draft', 'intro' => $q['intro'], 'notes' => $q['notes'],
            'contract_type' => $q['contract_type'], 'billing_cycle' => $q['billing_cycle'], 'auto_send_contract' => false,
        ]);
        $quotation->syncItems($q['items']);

        return $quotation;
    }

    /** A draft contract written straight from the proposal, for a client who has already agreed the work and needs no quotation. @param array<string, mixed> $c */
    private function createContract(Client $client, array $c): Contract
    {
        $contract = Contract::create([
            'client_id' => $client->id, 'title' => $c['title'], 'type' => $c['type'], 'billing_cycle' => $c['billing_cycle'], 'amount' => $c['amount'],
            'currency' => $c['currency'], 'start_date' => Carbon::parse($c['start_date'])->startOfDay(), 'end_date' => isset($c['end_date']) ? Carbon::parse($c['end_date']) : null,
            'status' => 'draft', 'client_signatory' => $client->contact_name, 'company_signatory' => config('company.signatory'),
        ]);
        Audit::log($contract, 'created', ['status' => 'draft']);

        return $contract;
    }

    /** @param array<string, mixed> $c */
    private function applyContractDetails(Contract $contract, array $c): void
    {
        $start = isset($c['start_date']) ? Carbon::parse($c['start_date'])->startOfDay() : now()->startOfDay()->addDays($c['start_in_days']);
        $contract->forceFill([
            'start_date' => $start, 'notice_period_days' => $c['notice_period_days'], 'payment_terms_days' => $c['payment_terms_days'],
            'deposit_percent' => $c['deposit_percent'], 'bill_in_arrears' => (bool) ($c['bill_in_arrears'] ?? false), 'scope' => $c['scope'], 'terms' => $c['terms'],
        ])->save();
    }

    /**
     * Earlier invoices must not clash with numbers already in use. One already on this client's profile is simply skipped.
     *
     * @param  list<array<string, mixed>>  $history
     */
    private function historyProblem(Client $client, array $history): ?string
    {
        foreach ($history as $h) {
            $existing = Invoice::where('reference', $h['reference'])->first();
            if ($existing && $existing->client_id !== $client->id) {
                return $h['reference'].' is already used by another client. Raise INVOICE_START_NUMBER in .env above it, then check which invoice should keep that number.';
            }
        }

        return null;
    }

    /**
     * Record invoices that were issued and settled outside the portal: the invoice under its original number, one
     * verified payment, and a receipt. The receipt number is set here so the client is NOT emailed for old payments.
     * Every earlier invoice (and its payment) is put on the contract, so the contract page shows the whole history and its
     * balance counts the payments. With a 'period_start' the invoice is the billing period starting that day, and the
     * daily run continues from the period after it; without one it is a one-off on the contract, outside the billing periods.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array{0: Invoice, 1: Payment}>
     */
    private function importHistory(Client $client, Contract $contract, array $history): array
    {
        $recordedBy = User::where('role', 'admin')->orderBy('id')->first();
        $done = [];
        usort($history, fn ($a, $b) => [$a['paid_on'], $a['reference']] <=> [$b['paid_on'], $b['reference']]);
        foreach ($history as $h) {
            $period = isset($h['period_start']) ? Carbon::parse($h['period_start'])->startOfDay() : null;
            $existing = Invoice::where('reference', $h['reference'])->where('client_id', $client->id)->first();
            if ($existing) {
                // Imported by an earlier run (perhaps before the proposal named its term, or onto a draft since withdrawn):
                // attach it to this contract now, so the term it paid for is not billed again and its payment counts here.
                if ($existing->contract_id === null || $existing->contract?->status === 'cancelled') {
                    $existing->forceFill(['contract_id' => $contract->id, 'kind' => $period ? 'cycle' : 'standard', 'period_start' => $period])->save();
                    $existing->payments()->update(['contract_id' => $contract->id]);
                    $this->line('  '.$h['reference'].' was already on this profile and is now attached to the contract'.($period ? ' as the '.$contract->cycle()['per'].' from '.$period->format('j M Y') : '').'.');
                } else {
                    $this->warn($h['reference'].' is already on this profile, so it was left as it is.');
                }

                continue;
            }
            $issued = Carbon::parse($h['issue_date'])->startOfDay();
            $invoice = Invoice::create([
                'reference' => $h['reference'], 'client_id' => $client->id, 'contract_id' => $contract->id, 'kind' => $period ? 'cycle' : 'standard', 'period_start' => $period,
                'title' => $h['title'], 'issue_date' => $issued, 'due_date' => Carbon::parse($h['due_date'])->startOfDay(), 'currency' => $h['currency'], 'tax_rate' => 0, 'status' => 'sent', 'sent_at' => $issued, 'notes' => $h['note'] ?? null,
            ]);
            $invoice->syncItems($h['items']);

            $paidOn = Carbon::parse($h['paid_on'])->startOfDay();
            $payment = Payment::create([
                'client_id' => $client->id, 'contract_id' => $contract->id, 'invoice_id' => $invoice->id, 'amount' => $invoice->total, 'currency' => $invoice->currency, 'method' => $h['method'],
                'paid_on' => $paidOn, 'reference' => $h['payment_reference'] ?? null, 'note' => 'Recorded by Code Canvas from the earlier invoice '.$h['reference'].'.',
                'status' => 'verified', 'submitted_by' => $recordedBy?->id, 'verified_by' => $recordedBy?->id, 'verified_at' => now(),
                'receipt_number' => Reference::next('receipt', Payment::class, 'receipt_number'),
            ]);
            $invoice->refreshPaymentStatus();
            $invoice->forceFill(['paid_at' => $paidOn])->save();
            Audit::log($payment, 'recorded', ['amount' => (float) $payment->amount, 'method' => $payment->method, 'imported' => true]);
            $done[] = [$invoice->fresh(), $payment];
        }

        return $done;
    }

    /** @param array<string, string> $spec */
    private function client(array $spec): ?Client
    {
        $search = $this->option('client') ?: $spec['search'];
        $matches = Client::where('company', 'like', '%'.$search.'%')->get();

        if ($matches->count() > 1) {
            $this->error('More than one client matches "'.$search.'". Narrow it with --client=:');
            $matches->each(fn (Client $c) => $this->line('  #'.$c->id.' '.$c->company));

            return null;
        }
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if (! $this->option('create-client')) {
            $this->error('No client matches "'.$search.'". Check the name with --client=, or add --create-client --email=<contact email> to create it.');

            return null;
        }
        if (! $this->option('email')) {
            $this->error('--create-client needs --email=<contact email> so the client has a valid contact.');

            return null;
        }

        return Client::create(['type' => 'company', 'company' => $spec['company'], 'contact_name' => $spec['contact_name'], 'email' => $this->option('email'), 'website' => $spec['website'] ?? null, 'active' => true]);
    }
}
