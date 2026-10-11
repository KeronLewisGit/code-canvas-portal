<x-layout title="Quotations">
    <x-page title="Quotations" :eyebrow="($quotations->total()).' total'">
        <x-slot:actions><a href="{{ route('admin.quotations.create') }}" class="btn btn-primary">New quotation</a></x-slot:actions>
    </x-page>
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <input name="q" type="search" aria-label="Search quotations" value="{{ request('q') }}" placeholder="Search title or reference" class="input max-w-xs">
        <select name="status" aria-label="Status" class="input max-w-40"><option value="">Any status</option>@foreach (config('company.quotation_statuses') as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="client" aria-label="Client" class="input max-w-52"><option value="">Any client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) request('client') === $c->id)>{{ $c->company }}</option>@endforeach</select>
        <button class="sr-only focus:not-sr-only btn btn-light">Apply filters</button>
    </form>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Quotation</th><th>Client</th><x-sort-th column="issued" label="Issued" /><x-sort-th column="valid" label="Valid until" /><th>Status</th><x-sort-th column="total" label="Total" :num="true" /></tr></thead><tbody>
            @forelse ($quotations as $q)<tr><td><a href="{{ route('admin.quotations.show', $q) }}" class="font-semibold hover:text-brand-700">{{ $q->reference }}</a><span class="block text-xs text-muted">{{ $q->title }}</span></td><td>{{ $q->client->company }}</td><td class="text-xs">{{ $q->issue_date->format('j M Y') }}</td><td class="text-xs">{{ $q->valid_until?->format('j M Y') ?? '—' }}</td><td><x-badge :status="$q->effectiveStatus()" /></td><td class="num font-semibold">{{ money($q->total, $q->currency) }}</td></tr>
            @empty<tr><td colspan="6"><x-empty>{{ request()->anyFilled(['q', 'status', 'client']) ? 'Nothing matches these filters.' : 'No quotations yet.' }}</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $quotations->links() }}</div>
</x-layout>
