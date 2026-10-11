@props(['href', 'title', 'meta' => null, 'status' => null, 'amount' => null, 'amountLabel' => null])
{{-- One row of a portal list as a stacked card, used below the sm breakpoint instead of a wide table. --}}
<li class="card p-4">
    <div class="flex items-start justify-between gap-3">
        <a href="{{ $href }}" class="min-w-0 font-semibold hover:text-brand-700">{{ $title }}@if ($meta)<span class="block text-sm font-normal text-muted">{{ $meta }}</span>@endif</a>
        @if ($status)<x-badge :status="$status" class="shrink-0" />@endif
    </div>
    @if ($amount !== null || $slot->isNotEmpty())
        <div class="mt-3 flex items-center justify-between gap-3">
            @if ($amount !== null)<p class="text-sm"><span class="text-muted">{{ $amountLabel }}</span> <span class="font-semibold tabular-nums">{{ $amount }}</span></p>@endif
            {{ $slot }}
        </div>
    @endif
</li>
