<x-layout title="Banking readiness report">
    @php($m = $report['meta']) @php($p = $report['portfolio']) @php($cur = $m['base_currency'])
    <x-page title="Reports" eyebrow="Banking readiness" :subtitle="'Issued '.(\Illuminate\Support\Carbon::parse($m['issued_at'])->format('j F Y, g:ia')).' · '.($m['contracts_included']).' contracts included'">
        <x-slot:actions>
            <button type="button" onclick="window.print()" class="btn btn-light">Print or save as PDF</button>
            <details class="relative">
                <summary class="btn btn-light list-none">Export CSV</summary>
                <div class="absolute right-0 z-10 mt-2 w-56 rounded-xl bg-white p-2 shadow-lg ring-1 ring-line">
                    @foreach (['register' => 'Signed register', 'summaries' => 'Contract summaries', 'receivables' => 'Receivables', 'payments' => 'Payments', 'forecast' => 'Cash-flow forecast'] as $part => $label)
                        <a href="{{ route('admin.reports.banking.export', ['part' => $part, 'all' => $all ? 1 : null]) }}" class="block rounded-lg px-3 py-2 text-sm font-semibold hover:bg-paper">{{ $label }}</a>
                    @endforeach
                </div>
            </details>
        </x-slot:actions>
    </x-page>
    <x-tabs :items="[['admin.reports.revenue', 'Revenue', 'admin.reports.revenue'], ['admin.reports.banking', 'Banking readiness report', 'admin.reports.banking']]" />

    <div class="no-print mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <p><span class="font-bold">{{ $m['contracts_excluded'] }} contract{{ $m['contracts_excluded'] === 1 ? '' : 's' }} excluded</span> because the client has not consented to disclosure or the contract is confidential{{ $m['excluded_references'] ? ': '.implode(', ', $m['excluded_references']) : '' }}. Record consent on the client's page before sharing this report.</p>
        @if (! $all)<a href="{{ route('admin.reports.banking', ['all' => 1]) }}" class="btn btn-light btn-sm">Preview with unconsented clients (internal only)</a>@else<a href="{{ route('admin.reports.banking') }}" class="btn btn-light btn-sm">Back to consented view</a>@endif
    </div>

    <div class="card prose-doc">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-5">
            <div><x-logo :full="true" class="h-12 w-auto" /><p class="mt-2 text-xs text-muted">{{ config('company.address') }} · {{ config('company.email') }} · {{ config('company.phone') }} · {{ config('company.website') }}</p></div>
            <div class="text-right text-sm"><p class="eyebrow">Banking readiness report</p><p class="font-semibold">Issued {{ \Illuminate\Support\Carbon::parse($m['issued_at'])->format('j F Y') }}</p><p class="text-xs text-muted">Prepared by {{ $m['issued_by'] }}<br>Base currency {{ $cur }} · indicative 1 USD = {{ number_format($m['usd_rate'], 2) }} {{ $cur }}<br>Verification {{ substr($m['verification_hash'], 0, 16) }}</p></div>
        </div>
        <p class="mt-4 rounded-xl bg-paper p-3 text-sm"><span class="font-bold">This report is not audited or certified.</span> It is a management summary generated from {{ config('company.name') }}'s client portal records on the issue date. Lenders should verify figures against bank statements and the supporting documents listed at the end.</p>

        <h2 class="mt-6 text-lg">1. Summary</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat label="Contracts" :value="$p['contracts']" :sub="$p['live'].' live'" />
            <x-stat label="Monthly recurring" :value="money($p['monthly_recurring'], $cur)" />
            <x-stat label="Contracted value" :value="money($p['contracted_value'], $cur)" :sub="$p['open_ended'] ? $p['open_ended'].' open-ended not valued' : null" />
            <x-stat label="12-month forecast" :value="money($p['forecast_12m'], $cur)" :sub="money($p['cancellable_12m'], $cur).' cancellable'" />
            <x-stat label="Invoiced to date" :value="money($p['invoiced'], $cur)" />
            <x-stat label="Collected to date" :value="money($p['collected'], $cur)" />
            <x-stat label="Receivables" :value="money($p['receivable'], $cur)" />
            <x-stat label="Overdue" :value="money($p['overdue'], $cur)" />
        </div>

        <h2 class="mt-6 text-lg">2. Signed contract register</h2>
        <div class="overflow-x-auto"><table class="table min-w-[900px]"><thead><tr><th>Ref</th><th>Client</th><th>Title</th><th>Term</th><th>Billing</th><th class="num">Contracted</th><th>Signed</th><th>Evidence</th></tr></thead><tbody>
            @forelse ($report['register'] as $r)<tr><td class="font-semibold">{{ $r['reference'] }}</td><td>{{ $r['client'] }}</td><td>{{ $r['title'] }}<span class="block text-xs text-muted">{{ $r['type'] }} · {{ $r['status'] }}</span></td><td class="text-xs whitespace-nowrap">{{ $r['start'] }}{{ $r['end'] ? ' to '.$r['end'] : ' open-ended' }}</td><td class="text-xs">{{ $r['billing'] }}<br>{{ money($r['amount'], $r['currency']) }}</td><td class="num">{{ $r['contracted_value'] === null ? 'Open-ended' : money($r['contracted_value'], $r['currency']) }}</td><td class="text-xs">{{ $r['signed_at'] ? \Illuminate\Support\Carbon::parse($r['signed_at'])->format('j M Y') : '' }}<br>{{ $r['signed_by'] }}</td><td class="text-xs">{{ $r['signature_method'] }}<br>{{ $r['evidence'] }}</td></tr>
            @empty<tr><td colspan="8" class="text-muted">No signed contracts with disclosure consent.</td></tr>@endforelse
        </tbody></table></div>

        <h2 class="mt-6 text-lg">3. Contract summaries</h2>
        <div class="overflow-x-auto"><table class="table min-w-[900px]"><thead><tr><th>Ref</th><th>Client</th><th>Status</th><th class="num">Contracted</th><th class="num">Invoiced</th><th class="num">Collected</th><th class="num">Receivable</th><th class="num">Overdue</th><th class="num">Monthly</th><th class="num">Next 12m</th></tr></thead><tbody>
            @foreach ($report['summaries'] as $s)<tr><td class="font-semibold">{{ $s['reference'] }}</td><td>{{ $s['client'] }}</td><td class="text-xs">{{ $s['status'] }}</td><td class="num">{{ $s['contracted_value'] === null ? 'Open-ended' : money($s['contracted_value'], $s['currency']) }}</td><td class="num">{{ money($s['invoiced'], $s['currency']) }}</td><td class="num">{{ money($s['collected'], $s['currency']) }}</td><td class="num">{{ money($s['receivable'], $s['currency']) }}</td><td class="num">{{ money($s['overdue'], $s['currency']) }}</td><td class="num">{{ money($s['monthly_recurring'], $s['currency']) }}</td><td class="num">{{ money($s['forecast_12m'], $s['currency']) }}</td></tr>@endforeach
        </tbody></table></div>

        <h2 class="mt-6 text-lg">4. Receivables history</h2>
        <div class="overflow-x-auto"><table class="table min-w-[800px]"><thead><tr><th>Invoice</th><th>Contract</th><th>Client</th><th>Issued</th><th>Due</th><th class="num">Total</th><th class="num">Collected</th><th class="num">Balance</th><th>Status</th><th class="num">Days to pay</th></tr></thead><tbody>
            @forelse ($report['receivables'] as $r)<tr><td class="font-semibold">{{ $r['invoice'] }}</td><td class="text-xs">{{ $r['contract'] }}</td><td>{{ $r['client'] }}</td><td class="text-xs">{{ $r['issued'] }}</td><td class="text-xs">{{ $r['due'] }}</td><td class="num">{{ money($r['total'], $r['currency']) }}</td><td class="num">{{ money($r['collected'], $r['currency']) }}</td><td class="num">{{ money($r['balance'], $r['currency']) }}</td><td class="text-xs">{{ $r['status'] }}</td><td class="num">{{ $r['days_to_pay'] ?? '—' }}</td></tr>
            @empty<tr><td colspan="10" class="text-muted">No invoices.</td></tr>@endforelse
        </tbody></table></div>

        <h2 class="mt-6 text-lg">5. Verified payments</h2>
        <div class="overflow-x-auto"><table class="table min-w-[700px]"><thead><tr><th>Date</th><th>Client</th><th>Contract</th><th>Invoice</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
            @forelse ($report['payments'] as $r)<tr><td class="text-xs whitespace-nowrap">{{ $r['date'] }}</td><td>{{ $r['client'] }}</td><td class="text-xs">{{ $r['contract'] }}</td><td class="text-xs">{{ $r['invoice'] }}</td><td class="text-xs">{{ $r['method'] }}</td><td class="text-xs">{{ $r['reference'] }}</td><td class="num">{{ money($r['amount'], $r['currency']) }}</td></tr>
            @empty<tr><td colspan="7" class="text-muted">No verified payments.</td></tr>@endforelse
        </tbody></table></div>

        <h2 class="mt-6 text-lg">6. Cash-flow forecast, next 12 months</h2>
        <table class="table max-w-2xl"><thead><tr><th>Month</th><th class="num">Expected billings</th><th class="num">Committed</th><th class="num">Cancellable on notice</th></tr></thead><tbody>
            @foreach ($p['forecast_by_month'] as $ym => $row)<tr><td>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $ym)->format('F Y') }}</td><td class="num">{{ money($row['amount'], $cur) }}</td><td class="num">{{ money($row['amount'] - $row['cancellable'], $cur) }}</td><td class="num">{{ money($row['cancellable'], $cur) }}</td></tr>@endforeach
            <tr class="font-bold"><td>Total</td><td class="num">{{ money($p['forecast_12m'], $cur) }}</td><td class="num">{{ money($p['forecast_12m'] - $p['cancellable_12m'], $cur) }}</td><td class="num">{{ money($p['cancellable_12m'], $cur) }}</td></tr>
        </tbody></table>

        <h2 class="mt-6 text-lg">7. Supporting documents</h2>
        <ul class="text-sm">
            @forelse ($report['documents'] as $d)<li>{{ $d['reference'] }} · {{ $d['client'] }}: {{ implode(' and ', array_filter([$d['electronic_signature'] ? 'electronic signature record' : null, $d['signed_copy'] ? 'signed copy on file' : null])) }}. <a href="{{ route('documents.contract', ['contract' => \App\Models\Contract::where('reference', $d['reference'])->value('id')]) }}" target="_blank" class="no-print font-semibold text-brand-700 hover:underline">Open contract PDF</a></li>
            @empty<li class="text-muted">No signed documents on file.</li>@endforelse
        </ul>

        <h2 class="mt-6 text-lg">8. Assumptions and verification</h2>
        <ol class="list-decimal space-y-1 pl-5 text-sm">@foreach ($m['assumptions'] as $a)<li>{{ $a }}</li>@endforeach</ol>
        <p class="mt-4 text-xs text-muted">Dataset verification hash (SHA-256): <span class="font-mono">{{ $m['verification_hash'] }}</span>. Regenerating the report on the same records produces the same hash. Generated {{ $m['issued_at'] }} by {{ $m['issued_by'] }}. Access to this report is logged.</p>
    </div>
</x-layout>
