<x-layout :title="$client->company">
    <x-page :title="$client->company" eyebrow="Client" :back="[route('admin.clients.index'), 'Clients']">
        <x-slot:actions>
            <a href="{{ route('admin.clients.edit', $client) }}" class="btn btn-light">Edit</a>
            <a href="{{ route('documents.statement', $client) }}" target="_blank" class="btn btn-light">Statement</a>
            @if ($client->active && $client->users->isNotEmpty())<form method="post" action="{{ route('admin.clients.preview', $client) }}">@csrf<button class="btn btn-light">Preview as client</button></form>@endif
            <a href="{{ route('admin.quotations.create', ['client' => $client->id]) }}" class="btn btn-light">New quotation</a>
            <a href="{{ route('admin.invoices.create', ['client' => $client->id]) }}" class="btn btn-light">New invoice</a>
            <a href="{{ route('admin.contracts.create', ['client' => $client->id]) }}" class="btn btn-primary">New contract</a>
        </x-slot:actions>
    </x-page>

    @unless ($client->active)
        <div role="status" class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200"><span><span class="font-semibold">This client is inactive.</span> They cannot sign in, and no quotations, invoices, contracts or reminders are emailed to them.</span><a href="{{ route('admin.clients.edit', $client) }}" class="btn btn-light btn-sm">Reactivate</a></div>
    @endunless
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Details</h2>
                <dl class="mt-4 space-y-2">
                    <div><dt class="eyebrow">Type</dt><dd class="font-semibold">{{ \App\Models\Client::TYPES[$client->type] ?? 'Company' }}</dd></div>
                    @unless ($client->isIndividual())<div><dt class="eyebrow">Contact</dt><dd class="font-semibold">{{ $client->contact_name }}</dd></div>@endunless
                    <div><dt class="eyebrow">Email</dt><dd><a href="mailto:{{ $client->email }}" class="hover:text-brand-700">{{ $client->email }}</a></dd></div>
                    @if ($client->phone)<div><dt class="eyebrow">Phone</dt><dd>{{ $client->phone }}</dd></div>@endif
                    @if ($client->website)<div><dt class="eyebrow">Website</dt><dd><a href="{{ $client->website }}" target="_blank" rel="noopener" class="hover:text-brand-700">{{ $client->website }}</a></dd></div>@endif
                    @if ($client->address)<div><dt class="eyebrow">Address</dt><dd>{{ $client->address }}</dd></div>@endif
                    <div><dt class="eyebrow">Due now</dt><dd class="text-lg font-bold tabular-nums">{{ money_by_currency($client->dueNow()) }}</dd></div>
                    @if (array_sum($remaining = $client->remainingOnContracts()) > 0)<div><dt class="eyebrow">Not invoiced yet</dt><dd class="font-semibold tabular-nums">{{ money_by_currency($remaining) }}</dd></div>@endif
                </dl>
                @if ($client->notes)<p class="mt-4 rounded-xl bg-paper p-3 text-xs whitespace-pre-line text-muted">{{ $client->notes }}</p>@endif
            </section>
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Portal logins</h2>
                <ul class="mt-4 space-y-2">
                    @forelse ($client->users as $u)
                        <li class="rounded-xl bg-paper p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ $u->name }}</p>
                                    <p class="truncate text-xs text-muted" title="{{ $u->email }}">{{ $u->email }}</p>
                                </div>
                                <form method="post" action="{{ route('admin.clients.users.destroy', [$client, $u]) }}" data-confirm="Remove this login? This person will no longer be able to sign in.">@csrf @method('delete')
                                    <button class="-m-1 grid size-8 shrink-0 cursor-pointer place-items-center rounded-lg text-muted transition hover:bg-red-50 hover:text-red-700 focus-visible:ring-4 focus-visible:ring-red-100 focus:outline-none" title="Remove login" aria-label="Remove login for {{ $u->name }}"><x-icon name="trash" class="size-4" /></button>
                                </form>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <form method="post" action="{{ route('admin.clients.users.update', [$client, $u]) }}" data-confirm="Email this person a link to choose a new password? Their current password keeps working until they do.">@csrf @method('patch')<input type="hidden" name="send_email" value="1">
                                    <button class="btn btn-light btn-sm w-full" title="Email a link to choose a new password"><x-icon name="mail" class="size-4" />Email link</button>
                                </form>
                                <form method="post" action="{{ route('admin.clients.users.update', [$client, $u]) }}" data-confirm="Generate a new password and show it to you only? The old one stops working immediately.">@csrf @method('patch')
                                    <button class="btn btn-light btn-sm w-full" title="Generate a new password and show it to you"><x-icon name="key" class="size-4" />Reset password</button>
                                </form>
                            </div>
                        </li>
                    @empty<li class="text-muted">No login yet. The client cannot see the portal until you create one.</li>@endforelse
                </ul>
                <form method="post" action="{{ route('admin.clients.users.store', $client) }}" class="mt-4 space-y-3 border-t border-line pt-4">
                    @csrf
                    <h3 class="font-semibold">{{ $client->users->isEmpty() ? 'Create a login' : 'Add another login' }}</h3>
                    <x-field label="Name" name="name" required><input id="name" name="name" value="{{ old('name', $client->contact_name) }}" required autocomplete="off" class="input"></x-field>
                    <x-field label="Email" name="email" required><input id="email" name="email" type="email" value="{{ old('email', $client->users->isEmpty() ? $client->email : '') }}" required autocomplete="off" class="input"></x-field>
                    <x-field label="Password" name="password" hint="Leave blank to generate a secure one."><input id="password" name="password" type="text" minlength="10" autocomplete="off" class="input"></x-field>
                    <label class="flex items-start gap-2"><input type="checkbox" name="send_email" value="1" @checked($errors->any() ? old('send_email') : true) class="mt-0.5 size-4 rounded border-line"><span><span class="font-semibold">Email the login details to the client</span><span class="block text-xs text-muted">Emails a link to choose their password. Untick to pass a password on yourself.</span></span></label>
                    <button class="btn btn-primary btn-sm">Create login</button>
                </form>
            </section>
        </div>

        <div class="space-y-6 lg:col-span-2">
            <section class="card overflow-x-auto">
                <h2 class="text-lg font-bold">Contracts</h2>
                <table class="table mt-4"><thead><tr><th>Contract</th><th>Dates</th><th>Status</th><th>Paid</th><th class="num">Amount</th></tr></thead><tbody>
                    @forelse ($client->contracts as $c)<tr><td><a href="{{ route('admin.contracts.show', $c) }}" class="font-semibold hover:text-brand-700">{{ $c->title }}</a><span class="block text-xs text-muted">{{ $c->reference }} · {{ $c->typeName() }}</span></td><td class="text-xs whitespace-nowrap">{{ $c->start_date->format('j M Y') }}{{ $c->end_date ? ' – '.$c->end_date->format('j M Y') : '' }}</td><td><x-badge :status="$c->status" /></td><td><x-badge :status="$c->paymentState()" /></td><td class="num font-semibold">{{ $c->amountLabel() }}</td></tr>
                    @empty<tr><td colspan="5" class="text-muted">No contracts yet.</td></tr>@endforelse
                </tbody></table>
            </section>
            <div class="grid gap-6 md:grid-cols-2">
                <section class="card overflow-x-auto">
                    <h2 class="text-lg font-bold">Quotations</h2>
                    <table class="table mt-4"><thead class="sr-only"><tr><th>Quotation</th><th>Status</th><th>Total</th></tr></thead><tbody>
                        @forelse ($client->quotations as $q)<tr><td><a href="{{ route('admin.quotations.show', $q) }}" class="font-semibold hover:text-brand-700">{{ $q->reference }}</a><span class="block text-xs text-muted">{{ $q->issue_date->format('j M Y') }}</span></td><td><x-badge :status="$q->effectiveStatus()" /></td><td class="num">{{ money($q->total, $q->currency) }}</td></tr>@empty<tr><td class="text-muted">None yet.</td></tr>@endforelse
                    </tbody></table>
                </section>
                <section class="card overflow-x-auto">
                    <h2 class="text-lg font-bold">Invoices</h2>
                    <table class="table mt-4"><thead class="sr-only"><tr><th>Invoice</th><th>Status</th><th>Total</th></tr></thead><tbody>
                        @forelse ($client->invoices as $i)<tr><td><a href="{{ route('admin.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a><span class="block text-xs text-muted">{{ $i->issue_date->format('j M Y') }}</span></td><td><x-badge :status="$i->effectiveStatus()" /></td><td class="num">{{ money($i->total, $i->currency) }}</td></tr>@empty<tr><td class="text-muted">None yet.</td></tr>@endforelse
                    </tbody></table>
                </section>
            </div>
            {{-- Payments only make sense once a contract has been invoiced; recorded payments always stay visible. --}}
            @if ($client->payments->isNotEmpty() || $client->invoices->contains(fn ($i) => $i->contract_id && ! in_array($i->status, ['draft', 'cancelled'])))
                <section class="card overflow-x-auto">
                    <x-card-header title="Payments"><a href="{{ route('admin.payments.create', ['client' => $client->id]) }}" class="btn btn-light btn-sm">Record a payment</a></x-card-header>
                    <table class="table mt-4"><thead><tr><th>Date</th><th>Method</th><th>For</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
                        @forelse ($client->payments as $p)<tr><td class="whitespace-nowrap"><a href="{{ route('admin.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->paid_on->format('j M Y') }}</a></td><td>{{ $p->methodName() }}</td><td class="text-xs">{{ $p->invoice?->reference ?? $p->contract?->reference ?? '—' }}</td><td><x-badge :status="$p->status" /></td><td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td></tr>
                        @empty<tr><td colspan="5" class="text-muted">No payments yet.</td></tr>@endforelse
                    </tbody></table>
                </section>
            @endif
        </div>
    </div>
</x-layout>
