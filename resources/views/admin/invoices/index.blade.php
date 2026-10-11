<x-layout title="Invoices">
    <x-page title="Invoices" :eyebrow="($invoices->total()).' total'">
        <x-slot:actions><a href="{{ route('admin.invoices.create') }}" class="btn btn-primary">New invoice</a></x-slot:actions>
    </x-page>
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <input name="q" type="search" aria-label="Search invoices" value="{{ request('q') }}" placeholder="Search title or reference" class="input max-w-xs">
        <select name="status" aria-label="Status" class="input max-w-40"><option value="">Any status</option>@foreach (config('company.invoice_statuses') as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="client" aria-label="Client" class="input max-w-52"><option value="">Any client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) request('client') === $c->id)>{{ $c->company }}</option>@endforeach</select>
        <button class="sr-only focus:not-sr-only btn btn-light">Apply filters</button>
    </form>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Invoice</th><th>Client</th><th>Issued</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th></tr></thead><tbody>
            @forelse ($invoices as $i)<tr><td><a href="{{ route('admin.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a><span class="block text-xs text-muted">{{ $i->title }}</span></td><td>{{ $i->client->company }}</td><td class="text-xs">{{ $i->issue_date->format('j M Y') }}</td><td class="text-xs">{{ $i->due_date?->format('j M Y') ?? '—' }}</td><td><x-badge :status="$i->effectiveStatus()" /></td><td class="num font-semibold">{{ money($i->total, $i->currency) }}</td><td class="num">{{ money($i->balance(), $i->currency) }}</td></tr>
            @empty<tr><td colspan="7"><x-empty>{{ request()->anyFilled(['q', 'status', 'client']) ? 'Nothing matches these filters.' : 'No invoices yet.' }}</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $invoices->links() }}</div>
</x-layout>
