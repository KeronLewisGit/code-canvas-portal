@props(['status', 'tone' => null])
{{-- Status chip. Labels come from the configured status names so the wording matches everywhere; pass a slot to override. --}}
@php($labels = config('company.contract_statuses') + config('company.quotation_statuses') + config('company.invoice_statuses') + config('company.payment_statuses') + ['unpaid' => 'Unpaid', 'not_due' => 'Nothing due yet', 'signed' => 'Signed'])
@php($tone ??= match ($status) {
    'active', 'verified', 'paid', 'accepted', 'completed', 'signed' => 'tone-green',
    'sent', 'partial', 'approved' => 'tone-blue',
    'pending', 'draft', 'unpaid' => 'tone-amber',
    'overdue', 'rejected', 'declined', 'cancelled', 'expired', 'terminated' => 'tone-red',
    default => 'tone-slate',
})
<span {{ $attributes->merge(['class' => 'chip '.$tone]) }}>{{ $slot->isEmpty() ? ($labels[$status] ?? ucfirst(str_replace('_', ' ', $status))) : $slot }}</span>
