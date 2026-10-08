<x-layout :title="$invoice->exists ? 'Edit invoice' : 'New invoice'">
    <x-page :title="$invoice->exists ? $invoice->reference : 'New invoice'" eyebrow="Invoice" :back="[$invoice->exists ? route('admin.invoices.show', $invoice) : route('admin.invoices.index'), $invoice->exists ? 'Back to invoice' : 'Invoices']" />
    <form method="post" action="{{ $invoice->exists ? route('admin.invoices.update', $invoice) : route('admin.invoices.store') }}" class="space-y-6">
        @csrf @if ($invoice->exists) @method('patch') @endif
        <div class="card grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-field label="Client" name="client_id" required class="sm:col-span-2">
                <select id="client_id" name="client_id" required class="input"><option value="">Choose a client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id', $invoice->client_id) === $c->id)>{{ $c->company }}</option>@endforeach</select>
            </x-field>
            <x-field label="Contract" name="contract_id" class="sm:col-span-2" hint="Payments against this invoice count toward the contract.">
                <select id="contract_id" name="contract_id" class="input"><option value="">None</option>@foreach ($contracts as $c)<option value="{{ $c->id }}" @selected((int) old('contract_id', $invoice->contract_id) === $c->id)>{{ $c->reference }} · {{ $c->client->company }} · {{ $c->title }}</option>@endforeach</select>
            </x-field>
            <x-field label="Title" name="title" required class="sm:col-span-2 lg:col-span-3"><input id="title" name="title" value="{{ old('title', $invoice->title) }}" required class="input" placeholder="e.g. Deposit: booking website"></x-field>
            <x-field label="Status" name="status" required>
                @php($locked = $invoice->exists && ! in_array($invoice->status, ['draft', 'sent']))
                <select id="status" name="status" class="input" @disabled($locked)><option value="draft" @selected(old('status', $invoice->status) === 'draft')>Draft (hidden from client)</option><option value="sent" @selected(old('status', $invoice->status) === 'sent')>Sent (visible to client)</option></select>
                @if ($locked)<input type="hidden" name="status" value="sent">@endif
            </x-field>
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
        <div class="flex gap-2"><button class="btn btn-primary">{{ $invoice->exists ? 'Save changes' : 'Create invoice' }}</button><a href="{{ $invoice->exists ? route('admin.invoices.show', $invoice) : route('admin.invoices.index') }}" class="btn btn-light">Cancel</a></div>
    </form>
</x-layout>
