<x-layout :title="$invoice->reference">
    <x-page :title="$invoice->title" :eyebrow="($invoice->reference).' · '.($invoice->client->company)" :back="[route('admin.invoices.index'), 'Invoices']">
        <x-slot:actions>
            <a href="{{ route('documents.invoice', $invoice) }}" target="_blank" class="btn btn-light">View PDF</a>
            {{-- Settled and cancelled invoices are the record; a sent one can be corrected, but the client has already seen it. --}}
            @if ($invoice->status === 'draft')<a href="{{ route('admin.invoices.edit', $invoice) }}" class="btn btn-light">Edit</a>
            @elseif (in_array($invoice->status, ['sent', 'partial']))<a href="{{ route('admin.invoices.edit', $invoice) }}" class="btn btn-light" data-confirm="The client has already received this invoice. Edit it anyway?">Edit</a>@endif
            @if ($invoice->status === 'draft')<form method="post" action="{{ route('admin.invoices.status', $invoice) }}" data-confirm="Email this invoice to the client now? They will be able to view and pay it.">@csrf @method('patch')<input type="hidden" name="status" value="sent"><button class="btn btn-primary">Send to client</button></form>@endif
            @if (in_array($invoice->status, ['sent', 'partial']) && $invoice->balance() > 0)<form method="post" action="{{ route('admin.invoices.remind', $invoice) }}" data-confirm="Email {{ $invoice->client->company }} a payment reminder?{{ $invoice->reminded_at ? ' The last one went '.$invoice->reminded_at->diffForHumans().'.' : '' }}">@csrf<button class="btn btn-light">Send reminder</button></form>@endif
            @if (! in_array($invoice->status, ['paid', 'cancelled', 'draft']))<a href="{{ route('admin.payments.create', ['invoice' => $invoice->id]) }}" class="btn btn-primary">Record a payment</a>@endif
        </x-slot:actions>
    </x-page>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2"><iframe src="{{ route('documents.invoice', [$invoice, 'embed' => 1]) }}" title="Invoice preview" class="h-[75vh] min-h-[28rem] w-full rounded-2xl lg:h-[1150px] ring-1 ring-line" loading="lazy"></iframe></div>
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Status</h2>
                <p class="mt-2"><x-badge :status="$invoice->effectiveStatus()" /></p>
                <dl class="mt-4 grid grid-cols-2 gap-2">
                    @if ($invoice->sent_at)<div><dt class="eyebrow">Sent</dt><dd>{{ $invoice->sent_at->format('j M Y') }}</dd></div>@endif
                    @if ($invoice->reminded_at)<div><dt class="eyebrow">Last reminder</dt><dd>{{ $invoice->reminded_at->diffForHumans() }}</dd></div>@endif
                    <div><dt class="eyebrow">Total</dt><dd class="font-bold tabular-nums">{{ money($invoice->total, $invoice->currency) }}</dd></div>
                    <div><dt class="eyebrow">Paid</dt><dd class="font-bold tabular-nums">{{ money($invoice->paidAmount(), $invoice->currency) }}</dd></div>
                    <div><dt class="eyebrow">Balance</dt><dd class="font-bold tabular-nums">{{ money($invoice->balance(), $invoice->currency) }}</dd></div>
                    <div><dt class="eyebrow">Due</dt><dd>{{ $invoice->due_date?->format('j M Y') ?? '—' }}</dd></div>
                </dl>
                <form method="post" action="{{ route('admin.invoices.status', $invoice) }}" class="mt-4 flex gap-2" data-confirm-field="status" data-confirm-map="{{ json_encode(['cancelled' => 'Cancel this invoice?', 'paid' => 'Mark this invoice paid? The remaining balance is recorded as settled outside the portal.']) }}">@csrf @method('patch')
                    <select name="status" aria-label="Invoice status" class="input"><option value="draft" @selected($invoice->status === 'draft')>Draft</option><option value="sent" @selected(in_array($invoice->status, ['sent', 'partial']))>Sent</option><option value="paid" @selected($invoice->status === 'paid')>Paid (manual)</option><option value="cancelled" @selected($invoice->status === 'cancelled')>Cancelled</option></select>
                    <button class="btn btn-light">Update</button>
                </form>
                <p class="mt-4 text-xs text-muted">Paid and partly paid are set automatically when linked payments are verified.</p>
            </section>
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Payments</h2>
                <ul class="mt-2 space-y-2">
                    @forelse ($invoice->payments as $p)<li class="flex items-center justify-between gap-2"><span><a href="{{ route('admin.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->paid_on->format('j M Y') }}</a> <x-badge :status="$p->status" /></span><span class="tabular-nums">{{ money($p->amount, $p->currency) }}</span></li>@empty<li class="text-muted">No payments against this invoice.</li>@endforelse
                </ul>
                @if ($invoice->contract)<p class="mt-4 text-xs text-muted">Contract <a href="{{ route('admin.contracts.show', $invoice->contract) }}" class="font-semibold text-brand-700">{{ $invoice->contract->reference }}</a></p>@endif
                @if ($invoice->quotation)<p class="mt-1 text-xs text-muted">From quotation <a href="{{ route('admin.quotations.show', $invoice->quotation) }}" class="font-semibold text-brand-700">{{ $invoice->quotation->reference }}</a></p>@endif
            </section>
            @php($lateFee = \App\Services\Adjustments::lateFeeFor($invoice))
            @php($refundable = \App\Services\Adjustments::refundableOnInvoice($invoice))
            @if (in_array($invoice->status, ['sent', 'partial']) || $lateFee || $refundable > 0)
                <section class="card text-sm">
                    <h2 class="text-lg font-bold">Adjust</h2>
                    @if (in_array($invoice->status, ['sent', 'partial']) && $invoice->balance() > 0)
                        <details class="mt-3" @if ($errors->has('amount') || $errors->has('reason')) open @endif><summary class="cursor-pointer font-semibold">Issue a credit note</summary>
                            <p class="mt-1 text-xs text-muted">Reduces what is owed (discount, write-off, goodwill). It is not counted as money received.</p>
                            <form method="post" action="{{ route('admin.invoices.credit', $invoice) }}" class="mt-2 space-y-2" data-confirm="Apply this credit note and email the client?">@csrf
                                <x-field label="Amount" name="amount" required><input id="amount" name="amount" type="number" step="0.01" min="0.01" max="{{ $invoice->balance() }}" value="{{ old('amount') }}" required class="input"></x-field>
                                <x-field label="Reason (the client sees this)" name="reason" required><input id="reason" name="reason" value="{{ old('reason') }}" required class="input"></x-field>
                                <button class="btn btn-light btn-sm">Apply credit note</button>
                            </form>
                        </details>
                    @endif
                    @if ($lateFee)
                        <form method="post" action="{{ route('admin.invoices.late-fee', $invoice) }}" class="mt-4" data-confirm="Issue a late fee invoice for {{ money($lateFee, $invoice->currency) }} and email the client?">@csrf
                            <button class="btn btn-light btn-sm">Charge late fee ({{ money($lateFee, $invoice->currency) }})</button>
                            <p class="mt-1 text-xs text-muted">One month at the rate in Settings, as a separate invoice. Available again in 30 days if still unpaid.</p>
                        </form>
                    @endif
                    @if ($refundable > 0)
                        @php($refundUrl = route('admin.invoices.refund', $invoice))
                        <details class="mt-4" @if ($errors->has('refund_amount')) open @endif><summary class="cursor-pointer font-semibold">Refund {{ money($refundable, $invoice->currency) }} overpaid</summary>
                        <form method="post" action="{{ $refundUrl }}" class="mt-2 space-y-2" data-confirm="Record this refund and email the client?">@csrf
                            <div class="grid grid-cols-2 gap-2">
                                <x-field label="Amount" name="refund_amount" required><input id="refund_amount" name="refund_amount" type="number" step="0.01" min="0.01" max="{{ $refundable }}" value="{{ old('refund_amount', $refundable) }}" required class="input"></x-field>
                                <x-field label="Paid back by" name="refund_method" required><select id="refund_method" name="refund_method" class="input"><option>bank transfer</option><option>cheque</option><option>cash</option></select></x-field>
                            </div>
                            <x-field label="Note (optional)" name="refund_note"><input id="refund_note" name="refund_note" value="{{ old('refund_note') }}" class="input" placeholder="e.g. transfer reference"></x-field>
                            <button class="btn btn-light btn-sm">Record refund</button>
                        </form>
                        </details>
                    @endif
                </section>
            @endif
            <form method="post" action="{{ route('admin.invoices.destroy', $invoice) }}" data-confirm="Delete this invoice?" class="text-right">@csrf @method('delete')<button class="btn-link-danger">Delete invoice</button></form>
        </div>
    </div>
</x-layout>
