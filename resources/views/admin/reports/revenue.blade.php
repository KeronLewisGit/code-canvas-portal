<x-layout title="Revenue">
    @php($cur = config('company.currency.code'))
    <x-page title="Reports" eyebrow="Revenue, receivables and forecasts">
        <x-slot:actions><a href="{{ route('admin.reports.banking.export', ['part' => 'forecast']) }}" class="btn btn-light" title="The banking forecast: all live contracts with disclosure consent, unaffected by the filters below">Download banking forecast CSV</a></x-slot:actions>
    </x-page>
    <x-tabs :items="[['admin.reports.revenue', 'Revenue', 'admin.reports.revenue'], ['admin.reports.banking', 'Banking readiness report', 'admin.reports.banking']]" />
    <p class="mb-4 text-sm text-muted">{{ $portfolio['live'] }} live of {{ $portfolio['contracts'] }} contracts in this view. Filters apply as you change them.</p>

    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <select name="status" class="input max-w-40" aria-label="Status"><option value="">Live and closed</option>@foreach (config('company.contract_statuses') as $k => $v)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="type" class="input max-w-48" aria-label="Type"><option value="">Any type</option>@foreach (config('company.contract_types') as $k => $v)<option value="{{ $k }}" @selected(($filters['type'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="cycle" class="input max-w-44" aria-label="Billing"><option value="">Any billing</option>@foreach (config('company.billing_cycles') as $k => $v)<option value="{{ $k }}" @selected(($filters['cycle'] ?? '') === $k)>{{ $v['label'] }}</option>@endforeach</select>
        <select name="client" class="input max-w-52" aria-label="Client"><option value="">Any client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client'] ?? 0) === $c->id)>{{ $c->company }}</option>@endforeach</select>
        @if ($currencies->count() > 1)<select name="currency" class="input max-w-32" aria-label="Currency"><option value="">All currencies</option>@foreach ($currencies as $c)<option value="{{ $c }}" @selected(($filters['currency'] ?? '') === $c)>{{ $c }}</option>@endforeach</select>@endif
        <button class="sr-only focus:not-sr-only btn btn-light">Apply filters</button>
        @if (array_filter($filters))<a href="{{ route('admin.reports.revenue') }}" class="btn btn-light">Clear</a>@endif
    </form>
    @if ($currencies->count() > 1 && empty($filters['currency']))<p role="status" class="mb-4 rounded-xl bg-amber-50 px-4 py-2 text-sm text-amber-900 ring-1 ring-amber-200">Contracts in more than one currency are shown. Totals below add amounts without conversion; filter by currency for exact figures.</p>@endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Monthly recurring" :value="money($portfolio['monthly_recurring'], $cur)" sub="Live recurring contracts, per month" />
        <x-stat label="Contracted value" :value="money($portfolio['contracted_value'], $cur)" :sub="$portfolio['open_ended'] ? $portfolio['open_ended'].' open-ended excluded' : 'Fixed-term and one-off'" />
        <x-stat label="Remaining to invoice" :value="money($portfolio['remaining_value'], $cur)" sub="Contracted value not yet billed" />
        <x-stat label="Next 12 months" :value="money($portfolio['forecast_12m'], $cur)" :sub="money($portfolio['cancellable_12m'], $cur).' cancellable on notice'" />
        <x-stat label="Total invoiced" :value="money($portfolio['invoiced'], $cur)" sub="Sent, partly paid and paid invoices" />
        <x-stat label="Total collected" :value="money($portfolio['collected'], $cur)" sub="Verified payments" />
        <x-stat label="Outstanding receivables" :value="money($portfolio['receivable'], $cur)" sub="Invoiced minus collected" />
        <x-stat label="Overdue" :value="money($portfolio['overdue'], $cur)" sub="Past due date" />
    </div>

    @php($series = $portfolio['forecast_by_month'])
    @php($max = max(1, (float) max(array_column($series, 'amount') ?: [0])))
    <section class="card mt-6" aria-labelledby="forecast-title">
        <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="forecast-title" class="text-lg font-bold">Forecast billings, next 12 months</h2><p class="text-sm text-muted">Expected invoices by month for live contracts · {{ $cur }}</p></div>
        @php($w = 760) @php($h = 220) @php($pad = 36) @php($n = max(1, count($series))) @php($slot = ($w - $pad) / $n) @php($bar = max(6, $slot - 2))
        <figure class="mt-4">
            <svg viewBox="0 0 {{ $w }} {{ $h + 28 }}" class="h-auto w-full" role="img" aria-label="Bar chart of forecast billings per month for the next 12 months; the table below has the same figures.">
                @foreach ([0, 0.5, 1] as $t)
                    @php($y = $h - $t * ($h - 34))
                    <line x1="{{ $pad }}" x2="{{ $w }}" y1="{{ $y }}" y2="{{ $y }}" stroke="#e3e3e3" stroke-width="1" />
                    <text x="{{ $pad - 6 }}" y="{{ $y + 4 }}" text-anchor="end" font-size="10" fill="#4e4b66">{{ $t === 0 ? 0 : number_format($max * $t / 1000, 1).'k' }}</text>
                @endforeach
                @foreach ($series as $ym => $row)
                    @php($i = $loop->index) @php($x = $pad + $i * $slot + 1) @php($bh = $row['amount'] > 0 ? max(4, ($row['amount'] / $max) * ($h - 34)) : 0) @php($y = $h - $bh)
                    <g>
                        <title>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $ym)->format('F Y') }}: {{ money($row['amount'], $cur) }}{{ $row['cancellable'] > 0 ? ' ('.money($row['cancellable'], $cur).' cancellable)' : '' }}</title>
                        @if ($bh > 0)<rect x="{{ $x }}" y="{{ $y }}" width="{{ $bar }}" height="{{ $bh }}" rx="4" fill="#1863dc" />@endif
                        @if ($row['amount'] > 0 && ($loop->first || $row['amount'] === $max || $loop->last))<text x="{{ $x + $bar / 2 }}" y="{{ $y - 6 }}" text-anchor="middle" font-size="11" font-weight="600" fill="#212121">{{ number_format($row['amount'] / 1000, 1) }}k</text>@endif
                        <text x="{{ $x + $bar / 2 }}" y="{{ $h + 18 }}" text-anchor="middle" font-size="11" fill="#4e4b66">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $ym)->format('M') }}</text>
                    </g>
                @endforeach
                <line x1="{{ $pad }}" x2="{{ $w }}" y1="{{ $h }}" y2="{{ $h }}" stroke="#80808c" stroke-width="1" />
            </svg>
        </figure>
        <details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-brand-700">Show as a table</summary>
            <table class="table mt-2 max-w-xl"><thead><tr><th>Month</th><th class="num">Forecast</th><th class="num">Committed</th><th class="num">Cancellable</th></tr></thead><tbody>
                @foreach ($series as $ym => $row)<tr><td>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $ym)->format('F Y') }}</td><td class="num">{{ money($row['amount'], $cur) }}</td><td class="num">{{ money($row['amount'] - $row['cancellable'], $cur) }}</td><td class="num">{{ money($row['cancellable'], $cur) }}</td></tr>@endforeach
            </tbody></table>
        </details>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="card text-sm">
            <h2 class="text-lg font-bold">Renewals due</h2>
            <p class="mt-1 text-muted">Ending within {{ config('company.renewal_alert_days') }} days with no renewal drafted.</p>
            <ul class="mt-4 space-y-2">
                @forelse ($alerts as $c)<li class="flex items-center justify-between gap-2"><span><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold text-brand-700 hover:underline">{{ $c->reference }}</a> <span class="text-muted">{{ $c->client->company }}</span><span class="block text-xs text-muted">Ends {{ $c->end_date->format('j M Y') }} · {{ $c->amountLabel() }}</span></span><form method="post" action="{{ route('admin.contracts.renew', $c) }}">@csrf<button class="btn btn-light btn-sm">Draft renewal</button></form></li>
                @empty<li class="text-muted">Nothing ending soon.</li>@endforelse
            </ul>
        </section>
        <section class="card text-sm">
            <h2 class="text-lg font-bold">Notice deadlines</h2>
            <p class="mt-1 text-muted">Clients who must give notice in the next 30 days to stop the next cycle.</p>
            <ul class="mt-4 space-y-2">
                @forelse ($notices as $c)<li><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold text-brand-700 hover:underline">{{ $c->reference }}</a> <span class="text-muted">{{ $c->client->company }}</span><span class="block text-xs text-muted">Notice by {{ $c->noticeDeadline()->format('j M Y') }} for the cycle starting {{ $c->nextDueDate()->format('j M Y') }}</span></li>
                @empty<li class="text-muted">No notice deadlines this month.</li>@endforelse
            </ul>
        </section>
        <section class="card text-sm">
            <h2 class="text-lg font-bold">Awaiting signature</h2>
            <p class="mt-1 text-muted">Approved or active contracts not yet signed.</p>
            <ul class="mt-4 space-y-2">
                @forelse ($unsigned as $c)<li><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold text-brand-700 hover:underline">{{ $c->reference }}</a> <span class="text-muted">{{ $c->client->company }}</span><span class="block text-xs text-muted">{{ $c->awaitingSignature() ? 'Sent '.$c->sent_for_signature_at->diffForHumans() : 'Not sent for signature yet' }}</span></li>
                @empty<li class="text-muted">Everything live is signed.</li>@endforelse
            </ul>
        </section>
    </div>

    <section class="card mt-6 overflow-x-auto">
        <h2 class="text-lg font-bold">Contracts in this view</h2>
        <table class="table mt-4 min-w-[900px]"><thead><tr><th>Contract</th><th>Client</th><th>Status</th><th>Billing</th><th class="num">Contracted</th><th class="num">Invoiced</th><th class="num">Collected</th><th class="num">Receivable</th><th class="num">Next 12m</th></tr></thead><tbody>
            @forelse ($contracts as $c)@php($f = $summaries[$c->id])
                <tr><td><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->reference }}</a><span class="block text-xs text-muted">{{ $c->title }}</span></td><td>{{ $c->client->company }}</td><td><x-badge :status="$c->effectiveStatus()" /></td><td class="text-xs">{{ $c->amountLabel() }}</td><td class="num">{{ $f['contracted_value'] === null ? 'Open-ended' : money($f['contracted_value'], $c->currency) }}</td><td class="num">{{ money($f['invoiced'], $c->currency) }}</td><td class="num">{{ money($f['collected'], $c->currency) }}</td><td class="num {{ $f['overdue'] > 0 ? 'text-red-700 font-semibold' : '' }}">{{ money($f['receivable'], $c->currency) }}</td><td class="num">{{ money($f['forecast_12m'], $c->currency) }}</td></tr>
            @empty<tr><td colspan="9" class="text-muted">No contracts match these filters.</td></tr>@endforelse
        </tbody></table>
    </section>
</x-layout>
