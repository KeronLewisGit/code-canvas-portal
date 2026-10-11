<x-layout :title="$invoice->reference">
    <x-page :title="$invoice->title" :eyebrow="'Invoice '.($invoice->reference)" :back="[route('portal.invoices.index'), 'Invoices']">
        <x-slot:actions>
            <a href="{{ route('documents.invoice', $invoice) }}" target="_blank" class="btn btn-light">View PDF</a>
            @if ($invoice->acceptsPayments())<a href="{{ route('portal.payments.create', ['invoice' => $invoice->id]) }}" class="btn btn-primary">Submit a payment</a>@endif
        </x-slot:actions>
    </x-page>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="hidden lg:col-span-2 lg:block"><iframe src="{{ route('documents.invoice', [$invoice, 'embed' => 1]) }}" title="Invoice preview" class="h-[75vh] min-h-[28rem] w-full rounded-2xl lg:h-[1150px] ring-1 ring-line" loading="lazy"></iframe></div>
        <div class="order-first space-y-6 lg:order-none">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Status</h2>
                <p class="mt-2"><x-badge :status="$invoice->effectiveStatus()" /></p>
                <dl class="mt-4 grid grid-cols-2 gap-2"><div><dt class="eyebrow">Paid</dt><dd class="font-bold tabular-nums">{{ money($invoice->paidAmount(), $invoice->currency) }}</dd></div><div><dt class="eyebrow">Balance</dt><dd class="font-bold tabular-nums">{{ money($invoice->balance(), $invoice->currency) }}</dd></div></dl>
                <h3 class="mt-6 font-bold">Payments</h3>
                <ul class="mt-2 space-y-2">@forelse ($invoice->payments as $p)<li class="flex items-center justify-between gap-2"><span><a href="{{ route('portal.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->paid_on->format('j M Y') }}</a> <x-badge :status="$p->status" /></span><span class="tabular-nums">{{ money($p->amount, $p->currency) }}</span></li>@empty<li class="text-muted">None yet.</li>@endforelse</ul>
            </section>
            @if ($invoice->acceptsPayments())
                @php($bank = config('company.bank'))
                @if ($cardAmount = \App\Services\WiPay::payableAmount($invoice))
                    <section class="card text-sm">
                        <h2 class="text-lg font-bold">Pay by card</h2>
                        <p class="mt-1 text-muted">Pay the {{ money($cardAmount, $invoice->currency) }} balance now with Visa or Mastercard. You complete the payment on WiPay's secure page and come straight back here.</p>
                        @error('card')<p class="error mt-2">{{ $message }}</p>@enderror
                        <form method="post" action="{{ route('portal.invoices.card', $invoice) }}" class="mt-4">@csrf<button class="btn btn-primary w-full">Pay {{ money($cardAmount, $invoice->currency) }} by card</button></form>
                    </section>
                @endif
                <section class="card text-sm">
                    <h2 class="text-lg font-bold">{{ \App\Services\WiPay::payableAmount($invoice) ? 'Or pay by bank transfer' : 'How to pay' }}</h2>
                    <dl class="mt-3 space-y-2">
                        <div><dt class="eyebrow">Bank</dt><dd class="font-semibold">{{ $bank['bank_name'] }}{{ $bank['branch'] ? ', '.$bank['branch'] : '' }}</dd></div>
                        <div><dt class="eyebrow">Account name</dt><dd class="font-semibold">{{ $bank['account_name'] }}</dd></div>
                        <div><dt class="eyebrow">Account number</dt><dd class="font-mono font-semibold">{{ $bank['account_number'] }}</dd></div>
                        <div><dt class="eyebrow">Reference</dt><dd class="font-semibold">{{ $invoice->reference }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-muted">Cheques payable to {{ $bank['cheque_payable_to'] }}. Once you have paid, tell us so we can confirm it.</p>
                    <a href="{{ route('portal.payments.create', ['invoice' => $invoice->id]) }}" class="btn btn-primary mt-4 w-full">I have paid: submit the payment</a>
                </section>
            @endif
            <x-doc-summary :doc="$invoice" :pdf="route('documents.invoice', $invoice)" />
        </div>
    </div>
</x-layout>
