{{-- Letter-size document sheets for a quotation or invoice. $doc, $kind ('Invoice' | 'Quotation'). --}}
@php($company = config('company'))
@php($isInvoice = $kind === 'Invoice')
@php($terms = $isInvoice ? \App\Models\Setting::get('invoice_terms', []) : \App\Models\Setting::get('quotation_terms', []))
<article class="doc">
    <header class="flex items-start justify-between gap-6">
        <div>
            <p class="doc-title">{{ strtoupper($kind) }}</p>
            <p class="mt-2 font-semibold">{{ $kind }} no. {{ $doc->reference }}</p>
            @if ($isInvoice)<p class="eyebrow mt-5">Balance due</p><p class="text-xl font-bold">{{ money($doc->balance(), $doc->currency) }}</p>
            @else<p class="eyebrow mt-5">Quoted total</p><p class="text-xl font-bold">{{ money($doc->total, $doc->currency) }}</p>@endif
        </div>
        <div class="text-right">
            <x-logo :full="true" class="ml-auto h-20 w-auto" />
            <p class="mt-4 font-semibold">{{ $company['trading_name'] }}</p>
            <p class="small muted">{{ $company['name'] }}<br>{{ $company['address'] }}<br>{{ $company['email'] }}@if ($company['phone'])<br>{{ $company['phone'] }}@endif @if ($company['tax_id'])<br>Tax ID {{ $company['tax_id'] }}@endif</p>
        </div>
    </header>

    <div class="mt-8 grid grid-cols-2 gap-6">
        <dl class="small grid grid-cols-[auto_1fr] gap-x-6 gap-y-1">
            <dt class="muted">{{ $kind }} date</dt><dd>{{ $doc->issue_date->format('j M Y') }}</dd>
            @if ($isInvoice)<dt class="muted">Terms</dt><dd>{{ $doc->termsLabel() }}</dd><dt class="muted">Due date</dt><dd>{{ $doc->due_date?->format('j M Y') ?? 'On receipt' }}</dd>
            @else<dt class="muted">Valid until</dt><dd>{{ $doc->valid_until?->format('j M Y') ?? '—' }}</dd>@endif
            @if ($isInvoice && $doc->contract)<dt class="muted">Contract</dt><dd>{{ $doc->contract->reference }}</dd>@endif
            @if ($isInvoice && $doc->quotation)<dt class="muted">Quotation</dt><dd>{{ $doc->quotation->reference }}</dd>@endif
        </dl>
        <div class="small">
            <p class="muted">{{ $isInvoice ? 'Bill to' : 'Prepared for' }}</p>
            <p class="font-semibold">{{ $doc->client->company }}</p>
            <p class="muted">@unless ($doc->client->isIndividual()){{ $doc->client->contact_name }}<br>@endunless @if ($doc->client->address){{ $doc->client->address }}<br>@endif{{ $doc->client->email }}@if ($doc->client->phone)<br>{{ $doc->client->phone }}@endif</p>
        </div>
    </div>

    @if (! $isInvoice && $doc->intro)<p class="mt-6">{{ $doc->intro }}</p>@endif
    <p class="mt-6 font-semibold">{{ $doc->title }}</p>

    <table class="doc-table mt-2">
        <thead><tr><th style="width:2.2em">#</th><th>Item &amp; description</th><th class="num" style="width:5em">Qty</th><th class="num" style="width:7em">Rate</th><th class="num" style="width:7.5em">Amount</th></tr></thead>
        <tbody>
            @foreach ($doc->items as $item)
                @php($lines = preg_split('/\r\n|\r|\n/', trim($item->description)))
                <tr><td class="num">{{ $loop->iteration }}</td><td>{{ array_shift($lines) }}@if ($lines)<span class="small muted block whitespace-pre-line">{{ implode("\n", $lines) }}</span>@endif</td><td class="num">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td><td class="num">{{ number_format($item->unit_price, 2) }}</td><td class="num">{{ number_format($item->total(), 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals mt-3 avoid-break">
        <div><span class="muted">Subtotal</span><span>{{ number_format($doc->subtotal, 2) }}</span></div>
        @if ((float) $doc->tax_rate > 0)<div><span class="muted">Tax ({{ rtrim(rtrim(number_format($doc->tax_rate, 2), '0'), '.') }}%)</span><span>{{ number_format($doc->tax_amount, 2) }}</span></div>@endif
        <div class="grand"><span>Total</span><span>{{ money($doc->total, $doc->currency) }}</span></div>
        @if ($isInvoice)
            @if ($doc->paidAmount() > 0)<div><span class="muted">Paid</span><span>{{ money($doc->paidAmount(), $doc->currency) }}</span></div>@endif
            <div class="due"><span>Balance due</span><span>{{ money($doc->balance(), $doc->currency) }}</span></div>
        @endif
    </div>

    <div class="mt-8 grid grid-cols-2 gap-6 avoid-break">
        @if ($isInvoice)
            <div class="small"><p class="font-semibold">Payment details</p><p class="muted">Bank: {{ $company['bank']['bank_name'] }}<br>Account name: {{ $company['bank']['account_name'] }}<br>Account type: {{ $company['bank']['account_type'] }}<br>Account number: {{ $company['bank']['account_number'] }}@if ($company['bank']['branch'])<br>Branch: {{ $company['bank']['branch'] }}@endif<br>Reference: {{ $doc->reference }}</p><p class="muted mt-2">Cheques payable to {{ $company['bank']['cheque_payable_to'] }}. After paying, submit the confirmation in the client portal.</p></div>
        @else
            <div class="small"><p class="font-semibold">Next step</p><p class="muted">Accept this quotation in the client portal or reply by email. Your contract follows acceptance, ready to sign online.</p></div>
        @endif
        @if ($doc->notes)<div class="small"><p class="font-semibold">Notes</p><p class="muted whitespace-pre-line">{{ $doc->notes }}</p></div>@endif
    </div>

@if ($terms)
    <section class="doc-terms">
        <p class="eyebrow">{{ $kind }} {{ $doc->reference }}</p>
        <h2 class="mt-1 text-lg font-semibold">Terms &amp; conditions</h2>
        @foreach ($terms as $heading => $text)<div><h3>{{ $heading }}</h3><p>{{ $text }}</p></div>@endforeach
    </section>
@endif
</article>
