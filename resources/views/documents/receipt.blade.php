@php($company = config('company'))
@php($client = $payment->client)
@php($void = $payment->receiptVoided())
<x-document-layout :reference="$payment->receipt_number" :title="'Receipt '.$payment->receipt_number" :back="auth()->user()->isAdmin() ? route('admin.payments.show', $payment) : route('portal.receipts.index')">
    <article class="doc">
        <header class="flex items-start justify-between gap-6">
            <div>
                <p class="doc-title">RECEIPT</p>
                <p class="mt-2 font-semibold">Receipt no. {{ $payment->receipt_number }}</p>
                <p class="small muted">Issued {{ ($payment->verified_at ?? $payment->paid_on)->format('j F Y') }}</p>
                <p class="eyebrow mt-5">Amount received</p>
                <p class="text-xl font-bold">{{ money($payment->amount, $payment->currency) }}</p>
            </div>
            <div class="text-right">
                <x-logo :full="true" class="ml-auto h-20 w-auto" />
                <p class="mt-4 font-semibold">{{ $company['trading_name'] }}</p>
                <p class="small muted">{{ $company['name'] }}<br>{{ $company['address'] }}<br>{{ $company['email'] }}@if ($company['phone'])<br>{{ $company['phone'] }}@endif @if ($company['tax_id'])<br>Tax ID {{ $company['tax_id'] }}@endif</p>
            </div>
        </header>

        @if ($void)
            <p class="mt-6 rounded-lg border-2 border-red-700 px-4 py-3 text-center text-lg font-bold tracking-widest text-red-700">VOID: this payment was reversed{{ $payment->rejection_reason ? ' ('.$payment->rejection_reason.')' : '' }}</p>
        @endif

        <div class="mt-8 grid grid-cols-2 gap-6">
            <div class="small">
                <p class="muted">Received from</p>
                <p class="font-semibold">{{ $client->company }}</p>
                <p class="muted">@unless ($client->isIndividual()){{ $client->contact_name }}<br>@endunless @if ($client->address){{ $client->address }}<br>@endif{{ $client->email }}</p>
            </div>
            <dl class="small grid grid-cols-[auto_1fr] gap-x-6 gap-y-1">
                <dt class="muted">Payment date</dt><dd>{{ $payment->paid_on->format('j M Y') }}</dd>
                <dt class="muted">Method</dt><dd>{{ $payment->methodName() }}</dd>
                @if ($payment->reference)<dt class="muted">Reference</dt><dd>{{ $payment->reference }}</dd>@endif
                @if ($payment->invoice)<dt class="muted">Invoice</dt><dd>{{ $payment->invoice->reference }}</dd>@endif
                @if ($payment->contract)<dt class="muted">Contract</dt><dd>{{ $payment->contract->reference }}</dd>@endif
            </dl>
        </div>

        <table class="doc-table mt-8">
            <thead><tr><th>Description</th><th class="num" style="width:9em">Amount</th></tr></thead>
            <tbody>
                <tr><td>Payment received{{ $payment->invoice ? ' for invoice '.$payment->invoice->reference.': '.$payment->invoice->title : ($payment->contract ? ' for contract '.$payment->contract->reference.': '.$payment->contract->title : '') }}</td><td class="num">{{ money($payment->amount, $payment->currency) }}</td></tr>
            </tbody>
        </table>
        <div class="totals mt-3 avoid-break">
            <div class="grand"><span>Total received</span><span>{{ money($payment->amount, $payment->currency) }}</span></div>
            @if (! $void && $payment->invoice)
                @php($balance = $payment->invoice->balance())
                <div class="due"><span>{{ $balance > 0 ? 'Balance remaining on '.$payment->invoice->reference : $payment->invoice->reference.' is paid in full' }}</span><span>{{ $balance > 0 ? money($balance, $payment->currency) : '' }}</span></div>
            @endif
        </div>

        <p class="small muted mt-10">Thank you for your payment. This receipt confirms that {{ $company['name'] }} received the amount above{{ $void ? ', but the payment was later reversed and this receipt is void' : '' }}. Balances shown are as at {{ now()->format('j F Y') }}. Questions? Email {{ $company['email'] }}.</p>
    </article>
</x-document-layout>
