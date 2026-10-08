<x-layout :title="$contract->exists ? 'Edit contract' : 'New contract'">
    <x-page :title="$contract->exists ? $contract->reference : 'New contract'" eyebrow="Contract" :back="[$contract->exists ? route('admin.contracts.show', $contract) : route('admin.contracts.index'), $contract->exists ? 'Back to contract' : 'Contracts']" />
    <form method="post" action="{{ $contract->exists ? route('admin.contracts.update', $contract) : route('admin.contracts.store') }}" enctype="multipart/form-data" class="grid gap-6 lg:grid-cols-3">
        @csrf @if ($contract->exists) @method('patch') @endif
        <div class="space-y-6 lg:col-span-2">
            <fieldset class="card grid gap-5 sm:grid-cols-2">
                <legend class="sr-only">Client and work</legend>
                <div class="sm:col-span-2"><h2 class="text-lg">1. Client and work</h2></div>
                <x-field label="Client" name="client_id" required class="sm:col-span-2">
                    <select id="client_id" name="client_id" required class="input"><option value="">Choose a client</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id', $contract->client_id) === $c->id)>{{ $c->company }} ({{ $c->contact_name }})</option>@endforeach</select>
                </x-field>
                <x-field label="Title" name="title" required class="sm:col-span-2" hint="What the client sees, e.g. Booking website for All Things Tobago."><input id="title" name="title" value="{{ old('title', $contract->title) }}" required class="input"></x-field>
                <x-field label="Kind of work" name="type" required hint="Used for grouping and reports only.">
                    <select id="type" name="type" class="input">@foreach (config('company.contract_types') as $k => $v)<option value="{{ $k }}" @selected(old('type', $contract->type) === $k)>{{ $v }}</option>@endforeach</select>
                </x-field>
                <x-field label="Status" name="status_display" hint="Changes through the steps on the contract page (approve, activate, terminate).">
                    <input id="status_display" value="{{ $contract->exists ? $contract->statusName() : 'Draft' }}" disabled class="input bg-paper">
                </x-field>
                <x-field label="Scope of work" name="scope" class="sm:col-span-2" hint="Shown to the client on the contract page. One item per line works well."><textarea id="scope" name="scope" rows="6" class="input">{{ old('scope', $contract->scope) }}</textarea></x-field>
            </fieldset>

            <fieldset class="card grid gap-5 sm:grid-cols-2">
                <legend class="sr-only">Price and billing</legend>
                <div class="sm:col-span-2"><h2 class="text-lg">2. Price and billing</h2><p class="mt-1 text-sm text-muted">How much, how often it is charged, and how long each invoice can stay unpaid. These are three separate things.</p></div>
                <x-field label="How is it charged?" name="billing_cycle" required hint="One-off: the amount is the whole price. Recurring: the amount is charged every cycle from the start date.">
                    <select id="billing_cycle" name="billing_cycle" class="input">@foreach (config('company.billing_cycles') as $k => $cycle)<option value="{{ $k }}" @selected(old('billing_cycle', $contract->billing_cycle ?? 'one_time') === $k)>{{ $cycle['label'] }}</option>@endforeach</select>
                </x-field>
                <x-field label="Amount" name="amount" required hint="The whole price for one-off work, or the price per cycle for recurring work."><input id="amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0" value="{{ old('amount', $contract->amount) }}" required class="input"></x-field>
                <x-field label="Currency" name="currency" required>
                    <select id="currency" name="currency" class="input">@foreach (config('company.currencies') as $k => $v)<option value="{{ $k }}" @selected(old('currency', $contract->currency) === $k)>{{ $k }} ({{ $v }})</option>@endforeach</select>
                </x-field>
                <x-field label="Each invoice is due within" name="payment_terms_days" required hint="Days after an invoice is issued before it is overdue. Nothing to do with how often you bill.">
                    <div class="flex items-center gap-2"><input id="payment_terms_days" name="payment_terms_days" type="number" inputmode="numeric" min="0" max="365" value="{{ old('payment_terms_days', $contract->payment_terms_days ?? 14) }}" required class="input max-w-28"><span class="text-sm text-muted">days</span></div>
                </x-field>
                <p class="text-sm text-muted sm:col-span-2">The standard Code Canvas terms print on page 2 of every contract. Edit them under <a href="{{ route('admin.settings.terms') }}" class="font-semibold text-brand-700 hover:underline">Settings</a>.</p>
            </fieldset>

            <fieldset class="card grid gap-5 sm:grid-cols-2">
                <legend class="sr-only">Dates and renewal</legend>
                <div class="sm:col-span-2"><h2 class="text-lg">3. Dates and renewal</h2></div>
                <x-field label="Start date" name="start_date" required><input id="start_date" name="start_date" type="date" value="{{ old('start_date', $contract->start_date?->format('Y-m-d')) }}" required class="input"></x-field>
                <x-field label="End date" name="end_date" hint="Leave blank for open-ended work or a rolling subscription."><input id="end_date" name="end_date" type="date" value="{{ old('end_date', $contract->end_date?->format('Y-m-d')) }}" class="input"></x-field>
                <x-field label="Notice period to stop" name="notice_period_days" hint="Recurring contracts only: how many days' notice the client must give before the next cycle. Leave blank if none.">
                    <div class="flex items-center gap-2"><input id="notice_period_days" name="notice_period_days" type="number" inputmode="numeric" min="0" max="365" value="{{ old('notice_period_days', $contract->notice_period_days) }}" class="input max-w-28"><span class="text-sm text-muted">days</span></div>
                </x-field>
                <label class="flex items-start gap-2 self-end pb-2 text-sm"><input type="checkbox" name="auto_renew" value="1" @checked(old('auto_renew', $contract->auto_renew)) class="mt-1 size-4 rounded"><span><span class="font-semibold">Auto-renew at the end date</span><span class="block text-muted">A renewal for the same term is created and activated automatically.</span></span></label>
            </fieldset>

            <fieldset class="card grid gap-5 sm:grid-cols-2">
                <legend class="sr-only">Signatories and privacy</legend>
                <div class="sm:col-span-2"><h2 class="text-lg">4. Signatories and privacy</h2></div>
                <x-field label="Signs for the client" name="client_signatory" hint="Defaults to the client contact."><input id="client_signatory" name="client_signatory" value="{{ old('client_signatory', $contract->client_signatory) }}" placeholder="{{ $contract->client?->contact_name ?? 'Client contact' }}" class="input"></x-field>
                <x-field label="Signs for Code Canvas" name="company_signatory"><input id="company_signatory" name="company_signatory" value="{{ old('company_signatory', $contract->company_signatory ?? config('company.signatory')) }}" class="input"></x-field>
                <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="confidential" value="1" @checked(old('confidential', $contract->confidential)) class="mt-1 size-4 rounded"><span><span class="font-semibold">Confidential</span><span class="block text-muted">Never include this contract in bank or lender reports, even if the client has consented to disclosure.</span></span></label>
            </fieldset>
        </div>
        <div class="space-y-6">
            @if ($contract->exists && $contract->status !== 'draft')
                <div class="card space-y-3">
                    <h2 class="text-lg font-bold">Amendment</h2>
                    <p class="text-sm text-muted">This contract is {{ strtolower($contract->statusName()) }} (version {{ $contract->version }}). Changing the amount, dates, billing, scope or terms keeps the current version on record and creates version {{ $contract->version + 1 }}.</p>
                    <x-field label="Reason for the change" name="amendment_reason" hint="Shown in the audit log and version history."><input id="amendment_reason" name="amendment_reason" value="{{ old('amendment_reason') }}" class="input" placeholder="e.g. Added monthly content updates"></x-field>
                </div>
            @endif
            <div class="card space-y-4">
                <h2 class="text-lg font-bold">Signed copy</h2>
                @if ($contract->file_path)
                    <p class="text-sm"><a href="{{ route('files.contract', $contract) }}" class="font-semibold text-brand-700 hover:underline">Download current file</a></p>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_file" value="1" class="size-4 rounded border-line"> Remove the file</label>
                @endif
                <x-field label="Upload PDF or image" name="file" hint="Up to 10 MB. Stored privately; only you and this client can download it."><input id="file" name="file" type="file" accept=".pdf,image/*" class="file-input"></x-field>
                <x-field label="Signed on" name="signed_at"><input id="signed_at" name="signed_at" type="date" value="{{ old('signed_at', $contract->signed_at?->format('Y-m-d')) }}" class="input"></x-field>
            </div>
            <div class="flex gap-2"><button class="btn btn-primary">{{ $contract->exists ? 'Save changes' : 'Create contract' }}</button><a href="{{ $contract->exists ? route('admin.contracts.show', $contract) : route('admin.contracts.index') }}" class="btn btn-light">Cancel</a></div>
        </div>
    </form>
</x-layout>
