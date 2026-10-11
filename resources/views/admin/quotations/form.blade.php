<x-layout :title="$quotation->exists ? 'Edit quotation' : 'New quotation'">
    <x-page :title="$quotation->exists ? $quotation->reference : 'New quotation'" eyebrow="Quotation" :back="[$quotation->exists ? route('admin.quotations.show', $quotation) : route('admin.quotations.index'), $quotation->exists ? 'Back to quotation' : 'Quotations']" />
    <form method="post" action="{{ $quotation->exists ? route('admin.quotations.update', $quotation) : route('admin.quotations.store') }}" class="space-y-6">
        @csrf @if ($quotation->exists) @method('patch') @endif
        <div class="card grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-field label="Client" name="client_id" required class="sm:col-span-2">
                <select id="client_id" name="client_id" required class="input"><option value="">Choose a client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id', $quotation->client_id) === $c->id)>{{ $c->company }}</option>@endforeach</select>
            </x-field>
            <x-field label="Related contract" name="contract_id" class="sm:col-span-2" hint="Optional.">
                <select id="contract_id" name="contract_id" class="input"><option value="">None</option>@foreach ($contracts as $c)<option value="{{ $c->id }}" @selected((int) old('contract_id', $quotation->contract_id) === $c->id)>{{ $c->reference }} · {{ $c->client->company }} · {{ $c->title }}</option>@endforeach</select>
            </x-field>
            <x-field label="Title" name="title" required class="sm:col-span-2 lg:col-span-4"><input id="title" name="title" value="{{ old('title', $quotation->title) }}" required class="input" placeholder="e.g. Booking website and admin dashboard"></x-field>
            <x-field label="Issue date" name="issue_date" required><input id="issue_date" name="issue_date" type="date" value="{{ old('issue_date', $quotation->issue_date?->format('Y-m-d')) }}" required class="input"></x-field>
            <x-field label="Valid until" name="valid_until"><input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until', $quotation->valid_until?->format('Y-m-d')) }}" class="input"></x-field>
            <x-field label="Currency" name="currency" required>
                <select id="currency" name="currency" class="input">@foreach (config('company.currencies') as $k => $v)<option value="{{ $k }}" @selected(old('currency', $quotation->currency) === $k)>{{ $k }} ({{ $v }})</option>@endforeach</select>
            </x-field>
            <x-field label="Intro" name="intro" class="sm:col-span-2 lg:col-span-4" hint="A short paragraph above the line items."><textarea id="intro" name="intro" rows="3" class="input">{{ old('intro', $quotation->intro) }}</textarea></x-field>
        </div>
        <div class="card">
            <h2 class="mb-3 text-lg font-bold">Line items</h2>
            <x-line-items :items="old('items', collect($items)->map(fn ($i) => is_array($i) ? $i : $i->only('description', 'quantity', 'unit_price'))->all())" :tax-rate="old('tax_rate', $quotation->tax_rate ?? 0)" :currency="old('currency', $quotation->currency)" />
        </div>
        <div class="card">
            <h2 class="text-lg font-bold">When the client accepts</h2>
            <p class="mt-1 text-sm text-muted">Accepting this quotation generates the contract from it: same title, amount and line items. Not used if you linked an existing contract above.</p>
            <div class="mt-4 grid gap-5 sm:grid-cols-3">
                <x-field label="Contract type" name="contract_type">
                    <select id="contract_type" name="contract_type" class="input"><option value="">Decide when reviewing</option>@foreach (config('company.contract_types') as $k => $v)<option value="{{ $k }}" @selected(old('contract_type', $quotation->contract_type) === $k)>{{ $v }}</option>@endforeach</select>
                </x-field>
                <x-field label="Billing" name="billing_cycle" hint="For recurring billing, quote the amount per period.">
                    <select id="billing_cycle" name="billing_cycle" class="input" aria-describedby="billing_cycle-hint"><option value="">Decide when reviewing</option>@foreach (config('company.billing_cycles') as $k => $c)<option value="{{ $k }}" @selected(old('billing_cycle', $quotation->billing_cycle) === $k)>{{ $c['label'] }}</option>@endforeach</select>
                </x-field>
                <x-field label="Term in months" name="contract_term_months" hint="Leave blank for no fixed end date.">
                    <input id="contract_term_months" name="contract_term_months" type="number" min="1" max="120" inputmode="numeric" value="{{ old('contract_term_months', $quotation->contract_term_months) }}" class="input" aria-describedby="contract_term_months-hint">
                </x-field>
            </div>
            <label class="mt-5 flex items-start gap-2 rounded-xl bg-brand-50 p-4 text-sm ring-1 ring-brand-100"><input type="checkbox" name="auto_send_contract" value="1" @checked($errors->any() ? old('auto_send_contract') : $quotation->auto_send_contract) class="mt-1 size-4 rounded border-line"><span><span class="font-semibold">Send the contract for signature automatically</span><span class="block text-muted">The client is taken straight to their contract and emailed a link to sign. Untick to review the drafted contract yourself first.</span></span></label>
        </div>
        <div class="card">
            <x-field label="Notes and terms" name="notes" hint="Shown at the bottom of the document."><textarea id="notes" name="notes" rows="4" class="input">{{ old('notes', $quotation->notes ?? config('company.document_terms')) }}</textarea></x-field>
        </div>
        <x-save-buttons :sendable="! $quotation->exists || $quotation->status === 'draft'" send-label="Save and send to client"
            confirm="Email this quotation to the client now? They will be able to review, accept or decline it." :cancel="$quotation->exists ? route('admin.quotations.show', $quotation) : route('admin.quotations.index')" />
    </form>
</x-layout>
