<x-layout title="Contracts">
    <x-page title="Contracts" :eyebrow="($contracts->total()).' total'">
        <x-slot:actions><a href="{{ route('admin.contracts.create') }}" class="btn btn-primary">New contract</a></x-slot:actions>
    </x-page>
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <input name="q" type="search" aria-label="Search contracts" value="{{ request('q') }}" placeholder="Search title or reference" class="input max-w-xs">
        <select name="status" aria-label="Status" class="input max-w-40"><option value="">Any status</option>@foreach (config('company.contract_statuses') as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="type" aria-label="Type" class="input max-w-48"><option value="">Any type</option>@foreach (config('company.contract_types') as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="cycle" aria-label="Billing" class="input max-w-44"><option value="">Any billing</option>@foreach (config('company.billing_cycles') as $k => $v)<option value="{{ $k }}" @selected(request('cycle') === $k)>{{ $v['label'] }}</option>@endforeach</select>
        <select name="client" aria-label="Client" class="input max-w-52"><option value="">Any client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) request('client') === $c->id)>{{ $c->company }}</option>@endforeach</select>
        <button class="sr-only focus:not-sr-only btn btn-light">Apply filters</button>
    </form>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Contract</th><th>Client</th><th>Type</th><x-sort-th column="start" label="Dates" /><th>Status</th><th>Paid</th><x-sort-th column="amount" label="Amount" :num="true" /></tr></thead><tbody>
            @forelse ($contracts as $c)<tr>
                <td><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->title }}</a><span class="block text-xs text-muted">{{ $c->reference }}</span></td>
                <td><a href="{{ route('admin.clients.show', $c->client) }}" class="hover:text-brand-700">{{ $c->client->company }}</a></td>
                <td class="text-xs">{{ $c->typeName() }}</td>
                <td class="text-xs whitespace-nowrap">{{ $c->start_date->format('j M Y') }}{{ $c->end_date ? ' – '.$c->end_date->format('j M Y') : '' }}</td>
                <td><x-badge :status="$c->effectiveStatus()" /></td><td><x-badge :status="$c->paymentState()" /></td>
                <td class="num font-semibold">{{ $c->amountLabel() }}</td>
            </tr>@empty<tr><td colspan="7"><x-empty>No contracts match. Create one from a client page or the button above.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $contracts->links() }}</div>
</x-layout>
