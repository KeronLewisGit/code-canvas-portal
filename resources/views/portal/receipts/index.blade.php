<x-layout title="Receipts">
    <x-page title="Receipts" :eyebrow="$receipts->where('status', 'verified')->count().' issued'" subtitle="A receipt is issued for every payment we confirm. Open one to view, print or save it as a PDF." />
    <div class="card hidden overflow-x-auto p-0 sm:block sm:p-0">
        <table class="table min-w-[640px]"><thead><tr><th>Receipt</th><th>Date</th><th>For</th><th>Method</th><th class="num">Amount</th><th></th></tr></thead><tbody>
            @forelse ($receipts as $r)<tr>
                <td><a href="{{ route('documents.receipt', $r) }}" target="_blank" class="font-semibold hover:text-brand-700">{{ $r->receipt_number }}</a>@if ($r->receiptVoided())<x-badge status="cancelled" class="ml-2">Void</x-badge>@endif</td>
                <td class="whitespace-nowrap text-xs">{{ ($r->verified_at ?? $r->paid_on)->format('j M Y') }}</td>
                <td class="text-xs">{{ $r->invoice?->reference ?? $r->contract?->reference ?? '—' }}</td>
                <td class="text-xs">{{ $r->methodName() }}</td>
                <td class="num font-semibold">{{ money($r->amount, $r->currency) }}</td>
                <td class="num"><a href="{{ route('documents.receipt', $r) }}" target="_blank" class="btn btn-light btn-sm">View or print</a></td>
            </tr>
            @empty<tr><td colspan="6"><x-empty>No receipts yet. You will get one by email each time we confirm a payment.</x-empty></td></tr>@endforelse
        </tbody></table>
    </div>
    <ul class="space-y-3 sm:hidden">
        @forelse ($receipts as $r)
            <x-list-card :href="route('documents.receipt', $r)" :title="$r->receipt_number" :meta="($r->verified_at ?? $r->paid_on)->format('j M Y').' · '.$r->methodName().($r->invoice ? ' · '.$r->invoice->reference : '')" :status="$r->receiptVoided() ? 'cancelled' : null" amount-label="Amount" :amount="money($r->amount, $r->currency)">
                <a href="{{ route('documents.receipt', $r) }}" target="_blank" class="btn btn-light btn-sm">View or print</a>
            </x-list-card>
        @empty<li><x-empty>No receipts yet. You will get one by email each time we confirm a payment.</x-empty></li>@endforelse
    </ul>
</x-layout>
