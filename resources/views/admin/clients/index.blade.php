<x-layout title="Clients">
    <x-page title="Clients" :eyebrow="($clients->total()).' total'">
        <x-slot:actions><a href="{{ route('admin.clients.create') }}" class="btn btn-primary">Add client</a></x-slot:actions>
    </x-page>
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <input name="q" type="search" aria-label="Search clients" value="{{ request('q') }}" placeholder="Search name, contact or email" class="input max-w-sm">
        <select name="type" aria-label="Type" class="input max-w-44"><option value="">Any type</option>@foreach (\App\Models\Client::TYPES as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="status" aria-label="Status" class="input max-w-40"><option value="">Any status</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
        <button class="sr-only focus:not-sr-only btn btn-light">Search</button>
        @if (request()->hasAny(['q', 'type', 'status']) && request()->anyFilled(['q', 'type', 'status']))<a href="{{ route('admin.clients.index') }}" class="btn btn-light">Clear filters</a>@endif
    </form>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[720px]"><thead><tr><x-sort-th column="name" label="Client" /><th>Type</th><th>Contact</th><x-sort-th column="contracts" label="Contracts" /><th class="num">Due now</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse ($clients as $c)<tr><td><a href="{{ route('admin.clients.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->company }}</a></td><td>{{ \App\Models\Client::TYPES[$c->type] ?? 'Company' }}</td><td>@unless ($c->isIndividual()){{ $c->contact_name }}@endunless<span class="block {{ $c->isIndividual() ? '' : 'text-xs text-muted' }}">{{ $c->email }}</span></td><td>{{ $c->contracts_count }}</td><td class="num font-semibold">{{ array_sum($due = $c->dueNow()) > 0 ? money_by_currency($due) : '—' }}</td><td><x-badge :status="$c->active ? 'active' : 'inactive'" /></td><td class="num"><a href="{{ route('admin.clients.show', $c) }}" class="btn btn-light btn-sm">View</a></td></tr>
            @empty<tr><td colspan="7"><x-empty>{{ request()->anyFilled(['q', 'type', 'status']) ? 'No clients match these filters.' : 'No clients yet. Add your first one.' }}</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $clients->links() }}</div>
</x-layout>
