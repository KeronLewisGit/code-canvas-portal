<x-layout :title="'Audit log · '.$contract->reference">
    <x-page :title="'Audit log for '.$contract->reference" eyebrow="{{ $contract->title }}" :back="[route('admin.contracts.show', $contract), 'Back to contract']" />
    <div class="card overflow-x-auto">
        <table class="table min-w-[640px]"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody>
            @forelse ($activity as $a)
                <tr><td class="whitespace-nowrap">{{ $a->created_at->format('j M Y, g:ia') }}</td><td>{{ $a->user?->name ?? 'System' }}</td><td class="font-semibold">{{ ucfirst(str_replace('_', ' ', $a->action)) }}</td><td class="text-xs">@if ($a->changes)<ul>@foreach ($a->changes as $k => $v)<li>{{ $k }}: {{ is_array($v) ? (isset($v['from']) || isset($v['to']) ? ($v['from'] ?? '—').' → '.($v['to'] ?? '—') : json_encode($v)) : $v }}</li>@endforeach</ul>@endif</td><td class="text-xs">{{ $a->ip }}</td></tr>
            @empty<tr><td colspan="5" class="text-muted">Nothing recorded yet.</td></tr>@endforelse
        </tbody></table>
    </div>
    <div class="mt-4">{{ $activity->links() }}</div>
</x-layout>
