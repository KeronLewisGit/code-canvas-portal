@props(['label', 'value', 'sub' => null])
<div class="stat">
    <p class="eyebrow">{{ $label }}</p>
    <p class="stat-value">{{ $value }}</p>
    @if ($sub)<p class="mt-1 text-sm text-muted">{{ $sub }}</p>@endif
</div>
