@props(['column', 'label', 'num' => false])
{{-- A sortable column heading: first click sorts newest/highest first, the next click reverses it. --}}
@php($active = request('sort') === $column)
@php($dir = $active && request('dir') !== 'asc' ? 'desc' : ($active ? 'asc' : null))
<th @class(['num' => $num]) @if ($active) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
    <a href="{{ request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir === 'desc' ? 'asc' : 'desc', 'page' => null]) }}" class="inline-flex items-center gap-1 hover:text-ink">{{ $label }}<span aria-hidden="true" class="{{ $active ? '' : 'opacity-30' }}">{{ $dir === 'asc' ? '↑' : '↓' }}</span></a>
</th>
