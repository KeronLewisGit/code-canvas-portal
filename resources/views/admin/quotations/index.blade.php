<x-layout title="Quotations">
    <x-page title="Quotations" eyebrow="{{ $quotations->total() }} total">
        <x-slot:actions><a href="{{ route('admin.quotations.create') }}" class="btn btn-primary">New quotation</a></x-slot:actions>
    </x-page>
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <select name="status" class="input max-w-40"><option value="">Any status</option>@foreach (config('company.quotation_statuses') as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="client" class="input max-w-52"><option value="">Any client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) request('client') === $c->id)>{{ $c->company }}</option>@endforeach</select>
        <button class="sr-only focus:not-sr-only btn btn-light">Apply filters</button>
    </form>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Quotation</th><th>Client</th><th>Issued</th><th>Valid until</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>
            @forelse ($quotations as $q)<tr><td><a href="{{ route('admin.quotations.show', $q) }}" class="font-semibold hover:text-brand-700">{{ $q->reference }}</a><span class="block text-xs text-muted">{{ $q->title }}</span></td><td>{{ $q->client->company }}</td><td class="text-xs">{{ $q->issue_date->format('j M Y') }}</td><td class="text-xs">{{ $q->valid_until?->format('j M Y') ?? '—' }}</td><td><x-badge :status="$q->effectiveStatus()" /></td><td class="num font-semibold">{{ money($q->total, $q->currency) }}</td></tr>
            @empty<tr><td colspan="6"><x-empty>No quotations yet.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $quotations->links() }}</div>
</x-layout>
