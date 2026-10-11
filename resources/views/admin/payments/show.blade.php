<x-layout title="Payment">
    <x-page :title="money($payment->amount, $payment->currency).' from '.$payment->client->company" :eyebrow="'Payment · '.($payment->methodName())" :back="[route('admin.payments.index'), 'Payments']" />
    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card text-sm lg:col-span-2">
            <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Details</h2><x-badge :status="$payment->status" /></div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><dt class="eyebrow">Client</dt><dd><a href="{{ route('admin.clients.show', $payment->client) }}" class="font-semibold hover:text-brand-700">{{ $payment->client->company }}</a></dd></div>
                <div><dt class="eyebrow">Date paid</dt><dd class="font-semibold">{{ $payment->paid_on->format('l j F Y') }}</dd></div>
                <div><dt class="eyebrow">Contract</dt><dd>@if ($payment->contract)<a href="{{ route('admin.contracts.show', $payment->contract) }}" class="font-semibold hover:text-brand-700">{{ $payment->contract->reference }}</a> · balance {{ money($payment->contract->balance(), $payment->currency) }}@else —@endif</dd></div>
                <div><dt class="eyebrow">Invoice</dt><dd>@if ($payment->invoice)<a href="{{ route('admin.invoices.show', $payment->invoice) }}" class="font-semibold hover:text-brand-700">{{ $payment->invoice->reference }}</a> · balance {{ money($payment->invoice->balance(), $payment->currency) }}@else —@endif</dd></div>
                <div><dt class="eyebrow">Method</dt><dd>{{ $payment->methodName() }}</dd></div>
                <div><dt class="eyebrow">Reference</dt><dd>{{ $payment->reference ?: '—' }}</dd></div>
                <div><dt class="eyebrow">Submitted by</dt><dd>{{ $payment->submitter?->name ?? '—' }} · {{ $payment->created_at->format('j M Y, g:ia') }}</dd></div>
                @if ($payment->verified_at)<div><dt class="eyebrow">{{ $payment->status === 'rejected' ? 'Rejected' : 'Verified' }} by</dt><dd>{{ $payment->verifier?->name }} · {{ $payment->verified_at->format('j M Y, g:ia') }}</dd></div>@endif
            </dl>
            @if ($payment->note)<p class="mt-4 rounded-xl bg-paper p-3 whitespace-pre-line">{{ $payment->note }}</p>@endif
            @if ($payment->rejection_reason)<p class="mt-4 rounded-xl bg-red-50 p-3 text-red-800"><span class="font-bold">Rejection reason:</span> {{ $payment->rejection_reason }}</p>@endif
            <div class="mt-6">
                <p class="eyebrow">Proof of payment</p>
                @if ($payment->proof_path)
                    @if (str_ends_with(strtolower($payment->proof_path), '.pdf'))<a href="{{ route('files.proof', $payment) }}" target="_blank" class="btn btn-light btn-sm mt-2">Open PDF</a>
                    @else<a href="{{ route('files.proof', $payment) }}" target="_blank" class="mt-2 block max-w-md overflow-hidden rounded-xl ring-1 ring-line"><img src="{{ route('files.proof', $payment) }}" alt="Proof of payment" class="w-full"></a>@endif
                @else<p class="mt-1 text-muted">None attached{{ $payment->method === 'bank_transfer' ? ' (required for bank transfers submitted by clients)' : '' }}.</p>@endif
            </div>
        </section>
        <div class="space-y-6">
            @if ($payment->status === 'pending')
                <section class="card space-y-3 text-sm">
                    <h2 class="text-lg font-bold">Verify this payment</h2>
                    <p class="text-muted">Check your bank statement, cash book or cheque before confirming. Verifying updates the contract and invoice balances.</p>
                    <form method="post" action="{{ route('admin.payments.verify', $payment) }}">@csrf @method('patch')<button class="btn btn-primary w-full">Confirm received</button></form>
                    <form method="post" action="{{ route('admin.payments.reject', $payment) }}" class="space-y-2 border-t border-line pt-3">@csrf @method('patch')
                        <x-field label="Reason (the client sees this)" name="rejection_reason" required><textarea id="rejection_reason" name="rejection_reason" rows="2" placeholder="e.g. the amount on the screenshot does not match" required class="input">{{ old('rejection_reason') }}</textarea></x-field>
                        <button class="btn btn-light w-full text-red-700">Reject</button>
                    </form>
                </section>
            @else
                <section class="card text-sm">
                    <h2 class="text-lg font-bold">Change decision</h2>
                    <p class="mt-1 text-muted">Made a mistake? You can flip the decision.</p>
                    <div class="mt-4 flex gap-2">
                        @if ($payment->status === 'rejected')<form method="post" action="{{ route('admin.payments.verify', $payment) }}">@csrf @method('patch')<button class="btn btn-light btn-sm">Verify instead</button></form>@endif
                        @if ($payment->status === 'verified')<form method="post" action="{{ route('admin.payments.reject', $payment) }}" data-confirm="Reverse this verification? The payment becomes rejected and the client is emailed.">@csrf @method('patch')<input type="hidden" name="rejection_reason" value="Reversed by {{ config('company.short') }}."><button class="btn btn-light btn-sm text-red-700">Reverse verification</button></form>@endif
                    </div>
                </section>
            @endif
            @if ($payment->status !== 'verified')<form method="post" action="{{ route('admin.payments.destroy', $payment) }}" data-confirm="Delete this payment record?" class="text-right">@csrf @method('delete')<button class="btn-link-danger">Delete payment</button></form>@endif
        </div>
    </div>
</x-layout>
