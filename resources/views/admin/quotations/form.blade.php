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
            <x-field label="Notes and terms" name="notes" hint="Shown at the bottom of the document."><textarea id="notes" name="notes" rows="4" class="input">{{ old('notes', $quotation->notes ?? config('company.document_terms')) }}</textarea></x-field>
        </div>
        <div class="flex gap-2"><button class="btn btn-primary">{{ $quotation->exists ? 'Save changes' : 'Create quotation' }}</button><a href="{{ $quotation->exists ? route('admin.quotations.show', $quotation) : route('admin.quotations.index') }}" class="btn btn-light">Cancel</a></div>
    </form>
</x-layout>
