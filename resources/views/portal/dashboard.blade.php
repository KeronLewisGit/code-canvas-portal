<x-layout title="Overview">
    <x-page :title="'Welcome, '.(auth()->user()->name).'.'" :eyebrow="$client->isIndividual() ? null : $client->company">
        @if ($canPay)
            <x-slot:actions><a href="{{ route('portal.payments.create') }}" class="btn btn-light">Submit a payment</a></x-slot:actions>
        @endif
    </x-page>
    @if ($brandNew)
        <section class="card flex flex-col items-start gap-4 sm:flex-row sm:items-center">
            <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-700"><x-icon name="quotations" class="size-6" /></span>
            <div class="flex-1">
                <h2 class="text-lg font-bold">Your portal is ready</h2>
                <p class="mt-1 text-sm text-muted">Nothing needs your attention yet. When we send you a quotation, contract or invoice it will appear here, and we will email you at {{ auth()->user()->email }}.</p>
            </div>
            <a href="{{ route('portal.welcome') }}" class="btn btn-light">Portal guide</a>
        </section>
    @else
    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Active contracts" :value="$contracts->filter->isLive()->count()" :sub="$contracts->count().' in total'" />
        <x-stat label="Due now" :value="money_by_currency($dueNow)" :sub="array_sum($remaining) > 0 ? money_by_currency($remaining).' more on your contracts, billed later' : 'On invoices we have sent you'" />
        <x-stat label="Payments being verified" :value="$pendingPayments->count()" :sub="money_by_currency(sum_by_currency($pendingPayments, fn ($p) => $p->amount))" />
    </div>
    <section class="card mt-6" aria-labelledby="attention-heading">
        <h2 id="attention-heading" class="text-lg font-bold">Needs your attention</h2>
        <ul class="mt-4 divide-y divide-line">
            @forelse ($attention as $item)
                <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3">
                    <span class="min-w-0 flex-1 basis-56"><x-badge :status="$item['badge']" class="mr-2">{{ $item['badgeText'] ?? null }}</x-badge><span class="font-semibold">{{ $item['title'] }}</span><span class="block text-sm text-muted">{{ $item['meta'] }}</span></span>
                    <a href="{{ $item['url'] }}" class="btn {{ $loop->first ? 'btn-primary' : 'btn-light btn-sm' }}">{{ $item['action'] }}</a>
                </li>
            @empty
                <li class="py-3 text-sm text-muted">You are all caught up. We will email you when something needs you.</li>
            @endforelse
        </ul>
    </section>
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
            <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Recent invoices</h2><a href="{{ route('portal.invoices.index') }}" class="text-sm font-semibold text-brand-700">See all</a></div>
            <ul class="mt-4 divide-y divide-line">
                @forelse ($recentInvoices as $i)
                    <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                        <a href="{{ route('portal.invoices.show', $i) }}" class="min-w-0 flex-1 basis-40 font-semibold hover:text-brand-700">{{ $i->reference }}<span class="block text-sm font-normal text-muted">{{ $i->title }}</span></a>
                        <span class="flex items-center gap-3"><x-badge :status="$i->effectiveStatus()" /><span class="text-sm font-semibold tabular-nums">{{ money($i->total, $i->currency) }}</span></span>
                    </li>
                @empty<li class="py-3 text-sm text-muted">No invoices yet.</li>@endforelse
            </ul>
        </section>
    </div>
    @endif
</x-layout>
