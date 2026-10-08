<x-layout :title="$invoice->reference">
    <x-page :title="$invoice->title" eyebrow="Invoice {{ $invoice->reference }}" :back="[route('portal.invoices.index'), 'Invoices']">
        <x-slot:actions>
            <a href="{{ route('documents.invoice', $invoice) }}" target="_blank" class="btn btn-light">Print / PDF</a>
            @if ($invoice->balance() > 0 && $invoice->status !== 'cancelled')<a href="{{ route('portal.payments.create', ['invoice' => $invoice->id]) }}" class="btn btn-primary">Submit a payment</a>@endif
        </x-slot:actions>
    </x-page>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2"><iframe src="{{ route('documents.invoice', [$invoice, 'embed' => 1]) }}" title="Invoice preview" class="h-[1150px] w-full rounded-2xl ring-1 ring-line" loading="lazy"></iframe></div>
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Status</h2>
                <p class="mt-2"><x-badge :status="$invoice->effectiveStatus()" /></p>
                <dl class="mt-4 grid grid-cols-2 gap-2"><div><dt class="eyebrow">Paid</dt><dd class="font-bold tabular-nums">{{ money($invoice->paidAmount(), $invoice->currency) }}</dd></div><div><dt class="eyebrow">Balance</dt><dd class="font-bold tabular-nums">{{ money($invoice->balance(), $invoice->currency) }}</dd></div></dl>
                <h3 class="mt-6 font-bold">Payments</h3>
                <ul class="mt-2 space-y-2">@forelse ($invoice->payments as $p)<li class="flex items-center justify-between gap-2"><span><a href="{{ route('portal.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->paid_on->format('j M Y') }}</a> <x-badge :status="$p->status" /></span><span class="tabular-nums">{{ money($p->amount, $p->currency) }}</span></li>@empty<li class="text-muted">None yet.</li>@endforelse</ul>
            </section>
        </div>
    </div>
</x-layout>
