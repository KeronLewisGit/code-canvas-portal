<x-layout title="Submit a payment">
    @php($bank = config('company.bank'))
    @php($methods = config('company.payment_methods'))
    <x-page title="Submit a payment" eyebrow="We verify every payment before it counts toward your balance" :back="[route('portal.payments.index'), 'Payments']" />
    <div class="grid gap-6 lg:grid-cols-3">
        <form method="post" action="{{ route('portal.payments.store') }}" enctype="multipart/form-data" class="card grid gap-5 sm:grid-cols-2 lg:col-span-2" x-data="paymentForm(@js(old('method', $payment->method)), @js(collect($methods)->map->proof))">
            @csrf
            <x-field label="Invoice" name="invoice_id" hint="Pick the invoice if you have one.">
                <select id="invoice_id" name="invoice_id" class="input"><option value="">No specific invoice</option>@foreach ($invoices as $i)<option value="{{ $i->id }}" @selected((int) old('invoice_id', $payment->invoice_id) === $i->id)>{{ $i->reference }} · balance {{ money($i->balance(), $i->currency) }}</option>@endforeach</select>
            </x-field>
            <x-field label="Contract" name="contract_id" hint="Or the contract this payment is for.">
                <select id="contract_id" name="contract_id" class="input"><option value="">Choose a contract</option>@foreach ($contracts as $c)<option value="{{ $c->id }}" @selected((int) old('contract_id', $payment->contract_id) === $c->id)>{{ $c->title }} · balance {{ money($c->balance(), $c->currency) }}</option>@endforeach</select>
            </x-field>
            <x-field label="Amount paid" name="amount" required><input id="amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0.01" value="{{ old('amount', $payment->amount) }}" required class="input"></x-field>
            <x-field label="Date paid" name="paid_on" required><input id="paid_on" name="paid_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('paid_on', $payment->paid_on?->format('Y-m-d')) }}" required class="input"></x-field>
            <x-field label="How did you pay?" name="method" required class="sm:col-span-2">
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($methods as $k => $m)
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl p-3 ring-1 ring-line" :class="method === '{{ $k }}' && 'bg-brand-50 ring-brand-400'"><input type="radio" name="method" value="{{ $k }}" x-model="method" @checked(old('method', $payment->method) === $k) class="mt-1"><span><span class="block text-sm font-semibold">{{ $m['label'] }}</span><span class="block text-xs text-muted">{{ $m['hint'] }}</span></span></label>
                    @endforeach
                </div>
            </x-field>
            <x-field label="Reference" name="reference" class="sm:col-span-2" hint="Transfer reference, cheque number, or who you handed the cash to."><input id="reference" name="reference" value="{{ old('reference') }}" class="input"></x-field>
            <div class="sm:col-span-2">
                <label for="proof" class="label"><span x-text="proofRequired ? 'Screenshot or PDF of the transfer' : 'Proof (optional)'">Proof</span><span x-show="proofRequired" class="text-red-700" aria-hidden="true"> *</span></label>
                <input id="proof" name="proof" type="file" accept=".pdf,image/*" :required="proofRequired" class="file-input">
                <p id="proof-hint" class="hint" x-text="(proofRequired ? 'Required for bank transfers. ' : 'A photo of the cheque or receipt helps us verify faster. ') + 'JPG, PNG, WebP or PDF, up to 10 MB.'">JPG, PNG, WebP or PDF, up to 10 MB.</p>
                @error('proof')<p id="proof-error" class="error" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-field label="Anything we should know?" name="note" class="sm:col-span-2"><textarea id="note" name="note" rows="2" class="input">{{ old('note') }}</textarea></x-field>
            <div class="flex gap-2 sm:col-span-2"><button class="btn btn-primary">Submit payment</button><a href="{{ route('portal.payments.index') }}" class="btn btn-light">Cancel</a></div>
        </form>
        <aside class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Bank transfer details</h2>
                <dl class="mt-4 space-y-2">
                    <div><dt class="eyebrow">Bank</dt><dd class="font-semibold">{{ $bank['bank_name'] }}{{ $bank['branch'] ? ', '.$bank['branch'] : '' }}</dd></div>
                    <div><dt class="eyebrow">Account name</dt><dd class="font-semibold">{{ $bank['account_name'] }}</dd></div>
                    <div><dt class="eyebrow">Account number</dt><dd class="font-mono font-semibold">{{ $bank['account_number'] }}</dd></div>
                    <div><dt class="eyebrow">Reference</dt><dd>Your invoice number</dd></div>
                </dl>
                <p class="mt-4 text-xs text-muted">Cheques payable to {{ $bank['cheque_payable_to'] }}. Card payments through the portal are coming soon.</p>
            </section>
        </aside>
    </div>
</x-layout>
