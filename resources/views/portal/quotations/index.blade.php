<x-layout title="Quotations">
    <x-page title="Quotations" :eyebrow="($quotations->count()).' total'" />
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Quotation</th><th>Issued</th><th>Valid until</th><th>Status</th><th class="num">Total</th><th></th></tr></thead><tbody>
            @forelse ($quotations as $q)<tr><td><a href="{{ route('portal.quotations.show', $q) }}" class="font-semibold hover:text-brand-700">{{ $q->reference }}</a><span class="block text-xs text-muted">{{ $q->title }}</span></td><td class="text-xs">{{ $q->issue_date->format('j M Y') }}</td><td class="text-xs">{{ $q->valid_until?->format('j M Y') ?? '—' }}</td><td><x-badge :status="$q->effectiveStatus()" /></td><td class="num font-semibold">{{ money($q->total, $q->currency) }}</td><td class="num"><a href="{{ route('portal.quotations.show', $q) }}" class="btn btn-light">{{ $q->canBeAnswered() ? 'Review quote' : 'View' }}</a></td></tr>
            @empty<tr><td colspan="6"><x-empty>No quotations yet.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
</x-layout>
