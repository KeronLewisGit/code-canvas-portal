<x-layout title="Contracts">
    <x-page title="Contracts" eyebrow="{{ $contracts->count() }} total" />
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Contract</th><th>Type</th><th>Dates</th><th>Status</th><th>Payment</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead><tbody>
            @forelse ($contracts as $c)<tr><td><a href="{{ route('portal.contracts.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->title }}</a><span class="block text-xs text-muted">{{ $c->reference }}</span></td><td class="text-xs">{{ $c->typeName() }}</td><td class="text-xs whitespace-nowrap">{{ $c->start_date->format('j M Y') }}{{ $c->end_date ? ' – '.$c->end_date->format('j M Y') : '' }}</td><td><x-badge :status="$c->effectiveStatus()" /></td><td><x-badge :status="$c->paymentState()" /></td><td class="num">{{ $c->amountLabel() }}</td><td class="num font-semibold">{{ money($c->balance(), $c->currency) }}</td></tr>
            @empty<tr><td colspan="7"><x-empty>No contracts yet.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
</x-layout>
