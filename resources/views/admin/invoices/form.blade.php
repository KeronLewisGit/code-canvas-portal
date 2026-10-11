<x-layout :title="$invoice->exists ? 'Edit invoice' : 'New invoice'">
    <x-page :title="$invoice->exists ? $invoice->reference : 'New invoice'" eyebrow="Invoice" :back="[$invoice->exists ? route('admin.invoices.show', $invoice) : route('admin.invoices.index'), $invoice->exists ? 'Back to invoice' : 'Invoices']" />
    <form method="post" action="{{ $invoice->exists ? route('admin.invoices.update', $invoice) : route('admin.invoices.store') }}" class="space-y-6">
        @csrf @if ($invoice->exists) @method('patch') @endif
        <input type="hidden" name="kind" value="{{ old('kind', $invoice->kind ?? 'standard') }}">
        <div class="card grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-field label="Client" name="client_id" required class="sm:col-span-2">
                <select id="client_id" name="client_id" required class="input"><option value="">Choose a client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id', $invoice->client_id) === $c->id)>{{ $c->company }}</option>@endforeach</select>
            </x-field>
            <x-field label="Contract" name="contract_id" class="sm:col-span-2" hint="Payments against this invoice count toward the contract.">
                <select id="contract_id" name="contract_id" class="input"><option value="">None</option>@foreach ($contracts as $c)<option value="{{ $c->id }}" @selected((int) old('contract_id', $invoice->contract_id) === $c->id)>{{ $c->reference }} · {{ $c->client->company }} · {{ $c->title }}</option>@endforeach</select>
            </x-field>
            <x-field label="Title" name="title" required class="sm:col-span-2 lg:col-span-3"><input id="title" name="title" value="{{ old('title', $invoice->title) }}" required class="input" placeholder="e.g. Deposit: booking website"></x-field>
            <x-field label="Issue date" name="issue_date" required><input id="issue_date" name="issue_date" type="date" value="{{ old('issue_date', $invoice->issue_date?->format('Y-m-d')) }}" required class="input"></x-field>
            <x-field label="Due date" name="due_date"><input id="due_date" name="due_date" type="date" value="{{ old('due_date', $invoice->due_date?->format('Y-m-d')) }}" class="input"></x-field>
            <x-field label="Currency" name="currency" required>
                <select id="currency" name="currency" class="input">@foreach (config('company.currencies') as $k => $v)<option value="{{ $k }}" @selected(old('currency', $invoice->currency) === $k)>{{ $k }} ({{ $v }})</option>@endforeach</select>
            </x-field>
        </div>
        <div class="card">
            <h2 class="mb-3 text-lg font-bold">Line items</h2>
            <x-line-items :items="old('items', collect($items)->map(fn ($i) => is_array($i) ? $i : $i->only('description', 'quantity', 'unit_price'))->all())" :tax-rate="old('tax_rate', $invoice->tax_rate ?? 0)" :currency="old('currency', $invoice->currency)" />
        </div>
        <div class="card">
            <x-field label="Notes and payment terms" name="notes" hint="Bank details are added automatically from the company settings."><textarea id="notes" name="notes" rows="4" class="input">{{ old('notes', $invoice->notes ?? config('company.document_terms')) }}</textarea></x-field>
        </div>
        {{-- Drafts stay hidden from the client; sending makes the invoice visible and emails them. Once sent, saving keeps it sent. --}}
        <x-save-buttons :sendable="! $invoice->exists || $invoice->status === 'draft'" field="status" draft-value="draft" :send-value="'sent'" :save-label="'Save changes'"
            send-label="Save and send to client" confirm="Email this invoice to the client now? They will be able to view and pay it." :cancel="$invoice->exists ? route('admin.invoices.show', $invoice) : route('admin.invoices.index')" />
        @if ($invoice->exists && $invoice->status !== 'draft')<input type="hidden" name="status" value="sent">@endif
    </form>
</x-layout>
