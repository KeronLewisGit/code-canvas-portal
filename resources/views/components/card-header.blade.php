@props(['title'])
{{-- Card heading with an optional action on the right; fixed height so side-by-side cards line up with or without one. --}}
<div {{ $attributes->merge(['class' => 'flex min-h-9 items-center justify-between gap-3']) }}>
    <h2 class="text-lg font-bold">{{ $title }}</h2>
    {{ $slot }}
</div>
