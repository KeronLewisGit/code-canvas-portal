@props(['items'])
{{-- Section tabs: [[route, label, pattern], ...] --}}
<nav class="no-print mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Sections">
    @foreach ($items as [$route, $label, $pattern])
        @php($on = request()->routeIs($pattern))
        <a href="{{ route($route) }}" class="-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold {{ $on ? 'border-brand-500 text-brand-700' : 'border-transparent text-muted hover:text-ink' }}" @if ($on) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
