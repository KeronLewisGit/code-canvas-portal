<x-layout title="Payments">
    <x-page title="Payments" eyebrow="{{ $payments->where('status', 'pending')->count() }} being verified">
        <x-slot:actions><a href="{{ route('portal.payments.create') }}" class="btn btn-primary">Submit a payment</a></x-slot:actions>
    </x-page>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Date</th><th>Method</th><th>For</th><th>Status</th><th class="num">Amount</th><th></th></tr></thead><tbody>
            @forelse ($payments as $p)<tr><td class="whitespace-nowrap">{{ $p->paid_on->format('j M Y') }}</td><td>{{ $p->methodName() }}<span class="block text-xs text-muted">{{ $p->reference }}</span></td><td class="text-xs">{{ $p->invoice?->reference }}{{ $p->invoice && $p->contract ? ' · ' : '' }}{{ $p->contract?->title }}</td><td><x-badge :status="$p->status" /></td><td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td><td class="num"><a href="{{ route('portal.payments.show', $p) }}" class="btn btn-light">View</a></td></tr>
            @empty<tr><td colspan="6"><x-empty>No payments submitted yet.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
</x-layout>
