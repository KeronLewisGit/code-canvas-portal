@props(['label', 'name', 'hint' => null, 'required' => false])
{{-- A labelText slot can replace the plain label, e.g. wording that changes with other fields. --}}
<div {{ $attributes->merge(['class' => '']) }}>
    <label for="{{ $name }}" class="label">{{ $labelText ?? $label }}@if ($required)<span class="text-red-700" aria-hidden="true"> *</span><span class="sr-only"> (required)</span>@endif</label>
    {{ $slot }}
    @if ($hint)<p id="{{ $name }}-hint" class="hint">{{ $hint }}</p>@endif
    @error($name)<p id="{{ $name }}-error" class="error">{{ $message }}</p>@enderror
</div>
