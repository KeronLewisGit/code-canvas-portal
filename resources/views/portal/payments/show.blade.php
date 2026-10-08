<x-layout title="Payment">
    <x-page :title="money($payment->amount, $payment->currency)" eyebrow="{{ $payment->methodName() }} · {{ $payment->paid_on->format('j F Y') }}" :back="[route('portal.payments.index'), 'Payments']" />
    <div class="card max-w-2xl text-sm">
        <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Status</h2><x-badge :status="$payment->status" /></div>
        @if ($payment->status === 'pending')<p class="mt-2 text-muted">We are checking this against our records. Your balance updates once it is verified, usually within one working day.</p>
        @elseif ($payment->status === 'verified')<p class="mt-2 text-muted">Verified on {{ $payment->verified_at->format('j F Y') }}. Thank you.</p>
        @else<div class="mt-2 rounded-xl bg-red-50 p-3 text-red-800"><p class="font-bold">This payment could not be verified.</p><p class="mt-1">{{ $payment->rejection_reason }}</p><a href="{{ route('portal.payments.create', array_filter(['invoice' => $payment->invoice_id, 'contract' => $payment->contract_id])) }}" class="mt-4 btn btn-primary">Submit again</a></div>@endif
        <dl class="mt-6 grid gap-4 sm:grid-cols-2">
            <div><dt class="eyebrow">For</dt><dd class="font-semibold">{{ $payment->invoice?->reference ?? '' }}{{ $payment->invoice && $payment->contract ? ' · ' : '' }}{{ $payment->contract?->title ?? '' }}</dd></div>
            <div><dt class="eyebrow">Reference</dt><dd>{{ $payment->reference ?: '—' }}</dd></div>
            <div><dt class="eyebrow">Submitted</dt><dd>{{ $payment->created_at->format('j M Y, g:ia') }}</dd></div>
            <div><dt class="eyebrow">Proof</dt><dd>@if ($payment->proof_path)<a href="{{ route('files.proof', $payment) }}" target="_blank" class="font-semibold text-brand-700 hover:underline">View file</a>@else —@endif</dd></div>
        </dl>
        @if ($payment->note)<p class="mt-4 rounded-xl bg-paper p-3 whitespace-pre-line">{{ $payment->note }}</p>@endif
    </div>
</x-layout>
