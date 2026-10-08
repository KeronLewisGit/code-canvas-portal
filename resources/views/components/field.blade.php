@props(['label', 'name', 'hint' => null, 'required' => false])
<div {{ $attributes->merge(['class' => '']) }}>
    <label for="{{ $name }}" class="label">{{ $label }}@if ($required)<span class="text-red-700" aria-hidden="true"> *</span><span class="sr-only"> (required)</span>@endif</label>
    {{ $slot }}
    @if ($hint)<p id="{{ $name }}-hint" class="hint">{{ $hint }}</p>@endif
    @error($name)<p id="{{ $name }}-error" class="error" role="alert">{{ $message }}</p>@enderror
</div>
