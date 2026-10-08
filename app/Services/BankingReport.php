<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Banking Readiness Report: a lender-facing package built from the live records.
 * Only contracts whose client has given disclosure consent, and that are not marked confidential, are included.
 * The report is not certified; it states its issue date, assumptions and a verification hash of the dataset.
 */
class BankingReport
{
    public function build(?Carbon $asOf = null, bool $includeUnconsented = false): array
    {
        $asOf = $asOf ?? now();
        $all = Contract::with('client', 'invoices', 'payments')->whereNotIn('status', ['draft', 'cancelled'])->orderBy('start_date')->get();
        $excluded = $all->filter(fn (Contract $c) => $c->confidential || ! $c->client->hasDisclosureConsent());
        $included = $includeUnconsented ? $all->reject(fn (Contract $c) => $c->confidential) : $all->diff($excluded);

        $register = $included->filter(fn (Contract $c) => $c->isSigned())->map(fn (Contract $c) => [
            'reference' => $c->reference, 'client' => $c->client->company, 'title' => $c->title, 'type' => $c->typeName(),
            'start' => $c->start_date->toDateString(), 'end' => $c->end_date?->toDateString(), 'status' => $c->statusName(),
            'billing' => $c->cycleName(), 'amount' => (float) $c->amount, 'currency' => $c->currency,
            'contracted_value' => $c->finance()->contractedValue(), 'signed_at' => $c->signed_at?->toDateTimeString(),
            'signed_by' => $c->signed_name ?: $c->clientSignatoryName(), 'signature_method' => $c->isSignedInPortal() ? 'Electronic (portal)' : 'Uploaded copy',
            'evidence' => $c->isSignedInPortal() ? 'Signature image, signer login, timestamp and IP on file' : ($c->file_path ? 'Signed document on file' : 'None'),
        ])->values();

        $summaries = $included->map(fn (Contract $c) => ['reference' => $c->reference, 'client' => $c->client->company, 'status' => $c->statusName(), 'currency' => $c->currency] + $c->finance()->summary())->values();

        $invoices = Invoice::with('client', 'contract')->whereIn('contract_id', $included->pluck('id'))->whereNotIn('status', ['draft', 'cancelled'])->orderBy('issue_date')->get();
        $receivables = $invoices->map(fn (Invoice $i) => [
            'invoice' => $i->reference, 'contract' => $i->contract?->reference, 'client' => $i->client->company, 'issued' => $i->issue_date->toDateString(), 'due' => $i->due_date?->toDateString(),
            'total' => (float) $i->total, 'collected' => $i->paidAmount(), 'balance' => $i->balance(), 'status' => $i->statusName(), 'currency' => $i->currency,
            'days_to_pay' => $i->paid_at ? (int) $i->issue_date->diffInDays($i->paid_at) : null,
        ])->values();

        $payments = Payment::with('client', 'contract', 'invoice')->whereIn('contract_id', $included->pluck('id'))->where('status', 'verified')->orderBy('paid_on')->get()->map(fn (Payment $p) => [
            'date' => $p->paid_on->toDateString(), 'client' => $p->client->company, 'contract' => $p->contract?->reference, 'invoice' => $p->invoice?->reference, 'method' => $p->methodName(), 'reference' => $p->reference, 'amount' => (float) $p->amount, 'currency' => $p->currency, 'verified_at' => $p->verified_at?->toDateTimeString(),
        ])->values();

        $portfolio = ContractFinance::portfolio($included);
        $documents = $included->filter(fn (Contract $c) => $c->file_path || $c->signature_path)->map(fn (Contract $c) => ['reference' => $c->reference, 'client' => $c->client->company, 'signed_copy' => (bool) $c->file_path, 'electronic_signature' => (bool) $c->signature_path])->values();

        $dataset = compact('register', 'summaries', 'receivables', 'payments', 'portfolio', 'documents');
        $dataset['meta'] = [
            'company' => config('company.name'), 'issued_at' => $asOf->toDateTimeString(), 'issued_by' => auth()->user()?->name,
            'base_currency' => config('company.currency.code'), 'usd_rate' => config('company.usd_rate'), 'include_unconsented' => $includeUnconsented,
            'contracts_included' => $included->count(), 'contracts_excluded' => $excluded->count(), 'excluded_references' => $excluded->pluck('reference')->values()->all(),
            'assumptions' => self::assumptions(),
            'certified' => false,
        ];
        $dataset['meta']['verification_hash'] = hash('sha256', json_encode([$register, $summaries, $receivables, $payments, $portfolio]));

        return $dataset;
    }

    public static function assumptions(): array
    {
        return [
            'Figures are taken from the portal records on the issue date and are not audited or certified.',
            'Collected amounts include only payments verified by '.config('company.short').' against bank, cash or cheque records.',
            'Contracted value is the one-off price, or the cycle price multiplied by the number of billing cycles in the term. Open-ended recurring contracts are shown as monthly recurring revenue with no finite value.',
            'The 12-month forecast counts billing cycles that start in the next 12 months for active contracts only. Expired, terminated, cancelled and completed contracts are excluded. Cancellable revenue is the part of the forecast on open-ended contracts beyond the client\'s notice period.',
            'Amounts are reported in the currency of each contract. The indicative TTD to USD rate printed on this report is for reference only and is not applied to the totals.',
            'Only contracts whose client has consented to disclosure, and which are not marked confidential, are included unless stated otherwise. Invoices and payments not linked to one of those contracts are not shown.',
        ];
    }
}
