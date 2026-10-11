<x-layout title="Clients">
    <x-page title="Clients" :eyebrow="($clients->total()).' total'">
        <x-slot:actions><a href="{{ route('admin.clients.create') }}" class="btn btn-primary">Add client</a></x-slot:actions>
    </x-page>
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit><input name="q" type="search" aria-label="Search clients" value="{{ request('q') }}" placeholder="Search company, contact or email" class="input max-w-sm"><button class="sr-only focus:not-sr-only btn btn-light">Search</button></form>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Client</th><th>Contact</th><th>Contracts</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse ($clients as $c)<tr><td><a href="{{ route('admin.clients.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->company }}</a></td><td>{{ $c->isIndividual() ? 'Individual' : $c->contact_name }}<span class="block text-xs text-muted">{{ $c->email }}</span></td><td>{{ $c->contracts_count }}</td><td><x-badge :status="$c->active ? 'active' : 'inactive'" /></td><td class="num"><a href="{{ route('admin.clients.show', $c) }}" class="btn btn-light btn-sm">View</a></td></tr>
            @empty<tr><td colspan="5"><x-empty>No clients yet. Add your first one.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $clients->links() }}</div>
</x-layout>
