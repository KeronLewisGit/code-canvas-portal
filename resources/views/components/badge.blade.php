@props(['status'])
@php($tone = match ($status) {
    'active', 'verified', 'paid', 'accepted' => 'tone-green',
    'sent', 'partial' => 'tone-blue',
    'pending', 'draft', 'unpaid' => 'tone-amber',
    'overdue', 'rejected', 'declined', 'cancelled', 'expired' => 'tone-red',
    default => 'tone-slate',
})
<span {{ $attributes->merge(['class' => 'chip '.$tone]) }}>{{ $slot->isEmpty() ? ucfirst(str_replace('_', ' ', $status)) : $slot }}</span>
