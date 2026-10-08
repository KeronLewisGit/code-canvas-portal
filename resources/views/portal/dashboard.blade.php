<x-layout title="Overview">
    <x-page title="Welcome, {{ auth()->user()->name }}." eyebrow="{{ $client->company }}">
        <x-slot:actions><a href="{{ route('portal.payments.create') }}" class="btn btn-primary">Submit a payment</a></x-slot:actions>
    </x-page>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Active contracts" :value="$contracts->filter->isLive()->count()" :sub="$contracts->count().' in total'" />
        <x-stat label="Balance outstanding" :value="money($outstanding)" sub="Verified payments deducted" />
        <x-stat label="Payments being verified" :value="$pendingPayments->count()" :sub="money($pendingPayments->sum('amount'))" />
    </div>
    @foreach ($contracts->filter->awaitingSignature() as $c)
        <div class="callout mt-6"><p><span class="font-semibold">{{ $c->title }} is ready for your signature.</span> Read it and sign online in about a minute.</p><a href="{{ route('portal.contracts.sign', $c) }}" class="btn btn-primary">Sign contract</a></div>
    @endforeach
    @if ($openQuotes->isNotEmpty())
        <section class="card mt-6 border-l-4 border-brand-500">
            <h2 class="text-lg font-bold">Quotations waiting for your answer</h2>
            <ul class="mt-4 space-y-2 text-sm">@foreach ($openQuotes as $q)<li class="flex items-center justify-between gap-2"><span><a href="{{ route('portal.quotations.show', $q) }}" class="font-semibold hover:text-brand-700">{{ $q->reference }}</a> · {{ $q->title }}</span><span class="flex items-center gap-3"><span class="tabular-nums">{{ money($q->total, $q->currency) }}</span><a href="{{ route('portal.quotations.show', $q) }}" class="btn btn-light">Review quote</a></span></li>@endforeach</ul>
        </section>
    @endif
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="card">
            <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Your contracts</h2><a href="{{ route('portal.contracts.index') }}" class="text-sm font-semibold text-brand-700">See all</a></div>
            <ul class="mt-4 divide-y divide-line">
                @forelse ($contracts->take(5) as $c)
                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                        <a href="{{ route('portal.contracts.show', $c) }}" class="min-w-0 flex-1 basis-48 font-semibold hover:text-brand-700">{{ $c->title }}<span class="block text-sm font-normal text-muted">{{ $c->reference }} · {{ $c->typeName() }}</span></a>
                        <span class="flex items-center gap-3"><x-badge :status="$c->paymentState()" /><span class="text-right text-sm font-semibold tabular-nums">{{ money($c->balance(), $c->currency) }}<span class="block text-xs font-normal text-muted">balance</span></span></span>
                    </li>
                @empty<li class="py-3 text-sm text-muted">No contracts yet.</li>@endforelse
            </ul>
        </section>
        <section class="card">
            <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Unpaid invoices</h2><a href="{{ route('portal.invoices.index') }}" class="text-sm font-semibold text-brand-700">See all</a></div>
            <ul class="mt-4 divide-y divide-line">
                @forelse ($unpaidInvoices as $i)
                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3">
                        <a href="{{ route('portal.invoices.show', $i) }}" class="min-w-0 flex-1 basis-40 font-semibold hover:text-brand-700">{{ $i->reference }}<span class="block text-sm font-normal text-muted">{{ $i->due_date ? 'Due '.$i->due_date->format('j M Y') : $i->title }}</span></a>
                        <span class="flex items-center gap-3"><x-badge :status="$i->effectiveStatus()" /><span class="text-sm font-semibold tabular-nums">{{ money($i->balance(), $i->currency) }}</span><a href="{{ route('portal.payments.create', ['invoice' => $i->id]) }}" class="btn btn-light btn-sm">Pay now</a></span>
                    </li>
                @empty<li class="py-3 text-sm text-muted">Nothing outstanding. Thank you.</li>@endforelse
            </ul>
        </section>
    </div>
</x-layout>
