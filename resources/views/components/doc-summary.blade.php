@props(['doc', 'pdf'])
{{-- Phone-sized summary of a quotation or invoice, shown below lg instead of the letter-width document preview. --}}
<section {{ $attributes->merge(['class' => 'card text-sm lg:hidden']) }}>
    <h2 class="text-lg font-bold">What it covers</h2>
    <ul class="mt-3 divide-y divide-line">
        @foreach ($doc->items as $item)
            @php($lines = preg_split('/\r\n|\r|\n/', trim($item->description)))
            <li class="flex items-start justify-between gap-3 py-2"><span class="min-w-0">{{ array_shift($lines) }}@if ($lines)<span class="block text-xs text-muted">{{ implode(' ', $lines) }}</span>@endif</span><span class="shrink-0 tabular-nums">{{ money($item->total(), $doc->currency) }}</span></li>
        @endforeach
    </ul>
    <p class="mt-3 flex justify-between border-t border-line pt-3 text-base font-bold"><span>Total</span><span class="tabular-nums">{{ money($doc->total, $doc->currency) }}</span></p>
    <a href="{{ $pdf }}" target="_blank" class="btn btn-light mt-4 w-full">View the full document (PDF)<span class="sr-only"> (opens in a new tab)</span></a>
</section>
