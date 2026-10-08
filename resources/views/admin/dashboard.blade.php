<x-layout title="Dashboard">
    <x-page title="Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ auth()->user()->name }}." eyebrow="{{ now()->format('l j F Y') }}">
        <x-slot:actions>
            <a href="{{ route('admin.quotations.create') }}" class="btn btn-light">New quotation</a>
            <a href="{{ route('admin.contracts.create') }}" class="btn btn-primary">New contract</a>
        </x-slot:actions>
    </x-page>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Payments to verify" :value="$stats['pending_payments']" :sub="money($stats['pending_amount']).' waiting for you'" />
        <x-stat label="Owed to you" :value="money($stats['outstanding'])" sub="Due to date, minus verified payments" />
        <x-stat label="Active contracts" :value="$stats['active_contracts']" :sub="money($stats['monthly_recurring']).' per month recurring'" />
        <x-stat label="Open quotations" :value="$stats['open_quotes']" sub="Waiting on a client answer" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="card overflow-x-auto">
            <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Payments awaiting verification</h2><a href="{{ route('admin.payments.index') }}" class="text-sm font-semibold text-brand-700">All payments</a></div>
            @if ($pending->isEmpty())<p class="mt-4 text-sm text-muted">Nothing waiting. Client submissions appear here.</p>@else
            <table class="table mt-4"><tbody>
                @foreach ($pending as $p)<tr><td><a href="{{ route('admin.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->client->company }}</a><span class="block text-xs text-muted">{{ $p->methodName() }} · {{ $p->paid_on->format('j M') }} · {{ $p->invoice?->reference ?? $p->contract?->reference }}</span></td><td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td><td class="num"><a href="{{ route('admin.payments.show', $p) }}" class="btn btn-light btn-sm">Review payment</a></td></tr>@endforeach
            </tbody></table>@endif
        </section>
        <section class="card">
            <h2 class="text-lg font-bold">Needs attention</h2>
            <div class="mt-4 space-y-3 text-sm">
                @forelse ($overdue as $i)<p class="flex items-center justify-between gap-3"><span><x-badge status="overdue" class="mr-2">Overdue</x-badge><a href="{{ route('admin.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a> {{ $i->client->company }}</span><span class="tabular-nums">{{ money($i->balance(), $i->currency) }}</span></p>@empty @endforelse
                @foreach ($ending as $c)<p class="flex items-center justify-between gap-3"><span><x-badge status="pending" class="mr-2">Ends {{ $c->end_date->format('j M') }}</x-badge><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->reference }}</a> {{ $c->client->company }}</span></p>@endforeach
                @foreach ($unsigned as $c)<p class="flex items-center justify-between gap-3"><span><x-badge status="pending" class="mr-2">Unsigned</x-badge><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->reference }}</a> {{ $c->client->company }}</span><span class="text-xs text-muted">{{ $c->awaitingSignature() ? 'sent '.$c->sent_for_signature_at->diffForHumans() : 'not sent' }}</span></p>@endforeach
                @if ($overdue->isEmpty() && $ending->isEmpty() && $unsigned->isEmpty())<p class="text-muted">Nothing overdue, nothing ending in the next 30 days, everything live is signed.</p>@endif
            </div>
            <p class="mt-4 text-sm"><a href="{{ route('admin.reports.revenue') }}" class="font-semibold text-brand-700 hover:underline">Revenue, forecasts and renewals in Reports</a></p>
        </section>
        <section class="card overflow-x-auto">
            <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Recent contracts</h2><a href="{{ route('admin.contracts.index') }}" class="text-sm font-semibold text-brand-700">All contracts</a></div>
            <table class="table mt-4"><tbody>
                @forelse ($recentContracts as $c)<tr><td><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->title }}</a><span class="block text-xs text-muted">{{ $c->reference }} · {{ $c->client->company }}</span></td><td><x-badge :status="$c->status" /></td><td><x-badge :status="$c->paymentState()" /></td><td class="num font-semibold">{{ $c->amountLabel() }}</td></tr>@empty<tr><td class="text-muted">No contracts yet.</td></tr>@endforelse
            </tbody></table>
        </section>
        <section class="card overflow-x-auto">
            <h2 class="text-lg font-bold">Recently verified</h2>
            <table class="table mt-4"><tbody>
                @forelse ($recentPayments as $p)<tr><td><a href="{{ route('admin.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->client->company }}</a><span class="block text-xs text-muted">{{ $p->methodName() }} · {{ $p->verified_at?->format('j M') }}</span></td><td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td></tr>@empty<tr><td class="text-muted">No verified payments yet.</td></tr>@endforelse
            </tbody></table>
        </section>
    </div>
</x-layout>
