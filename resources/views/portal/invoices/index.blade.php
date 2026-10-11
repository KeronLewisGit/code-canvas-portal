@php($new = app(\App\Services\UnreadDocuments::class)->unreadIds(auth()->user())['invoices'])
<x-layout title="Invoices">
    <x-page title="Invoices" :eyebrow="($invoices->count()).' total'">
        <x-slot:actions><a href="{{ route('documents.statement', auth()->user()->client) }}" target="_blank" class="btn btn-light">Statement of account</a></x-slot:actions>
    </x-page>
    <div class="card hidden overflow-x-auto p-0 sm:block sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th><th></th></tr></thead><tbody>
            @forelse ($invoices as $i)<tr><td>@if (in_array($i->id, $new, true))<span class="new-tag">New</span> @endif<a href="{{ route('portal.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a><span class="block text-xs text-muted">{{ $i->title }}</span></td><td class="text-xs">{{ $i->issue_date->format('j M Y') }}</td><td class="text-xs">{{ $i->due_date?->format('j M Y') ?? '—' }}</td><td><x-badge :status="$i->effectiveStatus()" /></td><td class="num">{{ money($i->total, $i->currency) }}</td><td class="num font-semibold">{{ money($i->balance(), $i->currency) }}</td><td class="num">@if ($i->acceptsPayments())<a href="{{ route('portal.payments.create', ['invoice' => $i->id]) }}" class="btn btn-primary btn-sm">Pay now</a>@endif</td></tr>
            @empty<tr><td colspan="7"><x-empty>No invoices yet.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <ul class="space-y-3 sm:hidden">
        @forelse ($invoices as $i)
            <x-list-card :href="route('portal.invoices.show', $i)" :title="$i->reference" :meta="$i->title.($i->due_date ? ' · due '.$i->due_date->format('j M Y') : '')" :status="$i->effectiveStatus()" amount-label="Balance" :amount="money($i->balance(), $i->currency)">
                @if ($i->acceptsPayments())<a href="{{ route('portal.payments.create', ['invoice' => $i->id]) }}" class="btn btn-primary btn-sm">Pay now</a>@endif
            </x-list-card>
        @empty<li><x-empty>No invoices yet.</x-empty></li>@endforelse
    </ul>
</x-layout>
