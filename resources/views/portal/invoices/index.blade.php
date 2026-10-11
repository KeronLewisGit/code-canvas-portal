<x-layout title="Invoices">
    <x-page title="Invoices" :eyebrow="($invoices->count()).' total'" />
    <div class="card overflow-x-auto p-0 sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th><th></th></tr></thead><tbody>
            @forelse ($invoices as $i)<tr><td><a href="{{ route('portal.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a><span class="block text-xs text-muted">{{ $i->title }}</span></td><td class="text-xs">{{ $i->issue_date->format('j M Y') }}</td><td class="text-xs">{{ $i->due_date?->format('j M Y') ?? '—' }}</td><td><x-badge :status="$i->effectiveStatus()" /></td><td class="num">{{ money($i->total, $i->currency) }}</td><td class="num font-semibold">{{ money($i->balance(), $i->currency) }}</td><td class="num">@if ($i->acceptsPayments())<a href="{{ route('portal.payments.create', ['invoice' => $i->id]) }}" class="btn btn-primary btn-sm">Pay now</a>@endif</td></tr>
            @empty<tr><td colspan="7"><x-empty>No invoices yet.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
</x-layout>
