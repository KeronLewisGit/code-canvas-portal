<x-layout title="Record a payment">
    <x-page title="Record a payment" subtitle="Payments you record count as verified straight away." :back="[route('admin.payments.index'), 'Payments']" />
    <form method="post" action="{{ route('admin.payments.store') }}" enctype="multipart/form-data" class="card grid max-w-3xl gap-5 sm:grid-cols-2" x-data="{ client: @js((string) old('client_id', $payment->client_id)) }">
        @csrf
        <x-field label="Client" name="client_id" required class="sm:col-span-2">
            <select id="client_id" name="client_id" required class="input" x-model="client"><option value="">Choose a client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id', $payment->client_id) === $c->id)>{{ $c->company }}</option>@endforeach</select>
        </x-field>
        <x-field label="Contract" name="contract_id" hint="Choose at least one of contract or invoice.">
            <select id="contract_id" name="contract_id" class="input"><option value="">None</option>@foreach ($contracts as $c)<option value="{{ $c->id }}" :hidden="client && client !== '{{ $c->client_id }}'" :disabled="client && client !== '{{ $c->client_id }}'" @selected((int) old('contract_id', $payment->contract_id) === $c->id)>{{ $c->reference }} · {{ $c->client->company }} · balance {{ money($c->balance(), $c->currency) }}</option>@endforeach</select>
        </x-field>
        <x-field label="Invoice" name="invoice_id">
            <select id="invoice_id" name="invoice_id" class="input"><option value="">None</option>@foreach ($invoices as $i)<option value="{{ $i->id }}" :hidden="client && client !== '{{ $i->client_id }}'" :disabled="client && client !== '{{ $i->client_id }}'" @selected((int) old('invoice_id', $payment->invoice_id) === $i->id)>{{ $i->reference }} · {{ $i->client->company }} · balance {{ money($i->balance(), $i->currency) }}</option>@endforeach</select>
        </x-field>
        <x-field label="Amount" name="amount" required><input id="amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0.01" value="{{ old('amount', $payment->amount) }}" required class="input"></x-field>
        <x-field label="Currency" name="currency" required>
            <select id="currency" name="currency" class="input">@foreach (config('company.currencies') as $k => $v)<option value="{{ $k }}" @selected(old('currency', $payment->currency) === $k)>{{ $k }}</option>@endforeach</select>
        </x-field>
        <x-field label="Method" name="method" required>
            <select id="method" name="method" class="input">@foreach (config('company.payment_methods') as $k => $m)<option value="{{ $k }}" @selected(old('method', $payment->method) === $k)>{{ $m['label'] }}</option>@endforeach</select>
        </x-field>
        <x-field label="Date received" name="paid_on" required><input id="paid_on" name="paid_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('paid_on', $payment->paid_on?->format('Y-m-d')) }}" required class="input"></x-field>
        <x-field label="Reference" name="reference" class="sm:col-span-2" hint="Transfer reference, cheque number, or who received the cash."><input id="reference" name="reference" value="{{ old('reference') }}" class="input"></x-field>
        <x-field label="Proof (optional for you)" name="proof" class="sm:col-span-2" hint="Screenshot or PDF, up to 10 MB."><input id="proof" name="proof" type="file" accept=".pdf,image/*" class="file-input"></x-field>
        <x-field label="Note" name="note" class="sm:col-span-2"><textarea id="note" name="note" rows="2" class="input">{{ old('note') }}</textarea></x-field>
        <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary">Record a payment</button><a href="{{ route('admin.payments.index') }}" class="btn btn-light">Cancel</a></div>
    </form>
</x-layout>
