<x-layout :title="$contract->reference.' v'.$record->version">
    <x-page :title="'Version '.$record->version.' of '.$contract->reference" eyebrow="Saved version" :subtitle="'Immutable snapshot, saved '.($record->created_at->format('j M Y, g:ia')).($record->author ? ' by '.$record->author->name : '').($record->reason ? ' · '.$record->reason : '')" :back="[route('admin.contracts.show', $contract), 'Back to contract']" />
    <div class="card overflow-x-auto">
        <table class="table min-w-[640px]"><thead><tr><th>Field</th><th>Version {{ $record->version }}</th><th>Current (v{{ $contract->version }})</th></tr></thead><tbody>
            @foreach ($record->snapshot as $field => $value)
                @php($now = $current[$field] ?? null)
                <tr class="{{ (string) $now !== (string) $value ? 'bg-amber-50/60' : '' }}"><td class="font-semibold">{{ ucfirst(str_replace('_', ' ', $field)) }}</td><td class="whitespace-pre-line">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : ($value ?? '—') }}</td><td class="whitespace-pre-line">{{ is_bool($now) ? ($now ? 'Yes' : 'No') : ($now ?? '—') }}</td></tr>
            @endforeach
        </tbody></table>
        <p class="mt-4 text-xs text-muted">Highlighted rows changed after this version. Versions are never edited or deleted.</p>
    </div>
</x-layout>
