@props(['title', 'eyebrow' => null, 'subtitle' => null, 'back' => null])
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        @if ($back)<a href="{{ $back[0] }}" class="no-print mb-1 inline-flex min-h-9 items-center text-sm font-semibold text-muted hover:text-ink">&larr; {{ $back[1] }}</a>@endif
        @if ($eyebrow)<p class="eyebrow">{{ $eyebrow }}</p>@endif
        <h1 class="h-page">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 max-w-2xl text-sm text-muted">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="no-print flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>
