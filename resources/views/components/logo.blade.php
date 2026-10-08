@props(['full' => false])
{{-- Code Canvas mark (isometric blue/purple) or the full wordmark for light backgrounds. --}}
@if ($full)
    <img src="{{ asset('images/brand/logo.png') }}" alt="{{ config('company.name') }}" {{ $attributes->merge(['class' => 'h-14 w-auto']) }}>
@else
    <img src="{{ asset('images/brand/mark.png') }}" alt="{{ config('company.name') }}" {{ $attributes->merge(['class' => 'size-8']) }}>
@endif
