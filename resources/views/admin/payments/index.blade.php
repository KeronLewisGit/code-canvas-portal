<x-layout title="Payments">
    <x-page title="Payments" :eyebrow="($pendingCount).' awaiting verification'">
        <x-slot:actions><a href="{{ route('admin.payments.create') }}" class="btn btn-primary">Record a payment</a></x-slot:actions>
    </x-page>
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <input name="q" type="search" aria-label="Search payments" value="{{ request('q') }}" placeholder="Search reference or client" class="input max-w-xs">
        <select name="status" aria-label="Status" class="input max-w-48"><option value="">Any status</option>@foreach (config('company.payment_statuses') as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="client" aria-label="Client" class="input max-w-52"><option value="">Any client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) request('client') === $c->id)>{{ $c->company }}</option>@endforeach</select>
        <button class="sr-only focus:not-sr-only btn btn-light">Apply filters</button>
    </form>
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><x-sort-th column="date" label="Date" /><th>Client</th><th>Method</th><th>For</th><th>Proof</th><th>Status</th><x-sort-th column="amount" label="Amount" :num="true" /><th></th></tr></thead><tbody>
            @forelse ($payments as $p)<tr class="{{ $p->status === 'pending' ? 'bg-amber-50/60' : '' }}">
                <td class="whitespace-nowrap">{{ $p->paid_on->format('j M Y') }}</td>
                <td><a href="{{ route('admin.clients.show', $p->client) }}" class="font-semibold hover:text-brand-700">{{ $p->client->company }}</a></td>
                <td>{{ $p->methodName() }}<span class="block text-xs text-muted">{{ $p->reference }}</span></td>
                <td class="text-xs">{{ $p->invoice?->reference }}{{ $p->invoice && $p->contract ? ' · ' : '' }}{{ $p->contract?->reference }}</td>
                <td class="text-xs">{{ $p->proof_path ? 'Attached' : '—' }}</td>
                <td><x-badge :status="$p->status" /></td>
                <td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td>
                <td class="num"><a href="{{ route('admin.payments.show', $p) }}" class="btn btn-light btn-sm">{{ $p->status === 'pending' ? 'Review' : 'View' }}</a></td>
            </tr>@empty<tr><td colspan="8"><x-empty>{{ request()->anyFilled(['q', 'status', 'client']) ? 'Nothing matches these filters.' : 'No payments yet. Clients submit them from their portal, or record one yourself.' }}</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $payments->links() }}</div>
</x-layout>
