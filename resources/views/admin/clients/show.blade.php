<x-layout :title="$client->company">
    <x-page :title="$client->company" eyebrow="Client" :back="[route('admin.clients.index'), 'Clients']">
        <x-slot:actions>
            <a href="{{ route('admin.clients.edit', $client) }}" class="btn btn-light">Edit</a>
            <a href="{{ route('admin.quotations.create', ['client' => $client->id]) }}" class="btn btn-light">New quotation</a>
            <a href="{{ route('admin.invoices.create', ['client' => $client->id]) }}" class="btn btn-light">New invoice</a>
            <a href="{{ route('admin.contracts.create', ['client' => $client->id]) }}" class="btn btn-primary">New contract</a>
        </x-slot:actions>
    </x-page>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Details</h2>
                <dl class="mt-4 space-y-2">
                    <div><dt class="eyebrow">Contact</dt><dd class="font-semibold">{{ $client->contact_name }}</dd></div>
                    <div><dt class="eyebrow">Email</dt><dd><a href="mailto:{{ $client->email }}" class="hover:text-brand-700">{{ $client->email }}</a></dd></div>
                    @if ($client->phone)<div><dt class="eyebrow">Phone</dt><dd>{{ $client->phone }}</dd></div>@endif
                    @if ($client->website)<div><dt class="eyebrow">Website</dt><dd><a href="{{ $client->website }}" target="_blank" rel="noopener" class="hover:text-brand-700">{{ $client->website }}</a></dd></div>@endif
                    @if ($client->address)<div><dt class="eyebrow">Address</dt><dd>{{ $client->address }}</dd></div>@endif
                    <div><dt class="eyebrow">Outstanding</dt><dd class="text-lg font-bold tabular-nums">{{ money($client->outstanding()) }}</dd></div>
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
                                <form method="post" action="{{ route('admin.clients.users.destroy', [$client, $u]) }}" onsubmit="return confirm('Remove this login? This person will no longer be able to sign in.')">@csrf @method('delete')
                                    <button class="-m-1 grid size-8 shrink-0 cursor-pointer place-items-center rounded-lg text-muted transition hover:bg-red-50 hover:text-red-700 focus-visible:ring-4 focus-visible:ring-red-100 focus:outline-none" title="Remove login" aria-label="Remove login for {{ $u->name }}"><x-icon name="trash" class="size-4" /></button>
                                </form>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                <form method="post" action="{{ route('admin.clients.users.update', [$client, $u]) }}" onsubmit="return confirm('Email new login details to this address? A new password is generated and sent, and the old one stops working immediately.')">@csrf @method('patch')<input type="hidden" name="send_email" value="1">
                                    <button class="btn btn-light btn-sm w-full" title="Generate a new password and email it to the client"><x-icon name="mail" class="size-4" />Email login</button>
                                </form>
                                <form method="post" action="{{ route('admin.clients.users.update', [$client, $u]) }}" onsubmit="return confirm('Generate a new password and show it to you only? The old one stops working immediately.')">@csrf @method('patch')
                                    <button class="btn btn-light btn-sm w-full" title="Generate a new password and show it to you"><x-icon name="key" class="size-4" />Reset password</button>
                                </form>
                            </div>
                        </li>
                    @empty<li class="text-muted">No login yet. The client cannot see the portal until you create one.</li>@endforelse
                </ul>
                <form method="post" action="{{ route('admin.clients.users.store', $client) }}" class="mt-4 space-y-3 border-t border-line pt-4">
                    @csrf
                    <p class="font-semibold">Create a login</p>
                    <input name="name" value="{{ old('name', $client->contact_name) }}" placeholder="Name" required class="input">
                    <input name="email" type="email" value="{{ old('email', $client->users->isEmpty() ? $client->email : '') }}" placeholder="Email" required class="input">
                    <input name="password" type="text" placeholder="Password (leave blank to generate one)" class="input" autocomplete="off">
                    <label class="flex items-start gap-2"><input type="checkbox" name="send_email" value="1" @checked(old('send_email', true)) class="mt-0.5 size-4 rounded border-line"><span><span class="font-semibold">Email the login details to the client</span><span class="block text-xs text-muted">Sends the email, password and a sign-in link to this address. Untick to pass them on yourself.</span></span></label>
                    <button class="btn btn-dark btn-sm">Create login</button>
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
                    <table class="table mt-4"><tbody>
                        @forelse ($client->quotations as $q)<tr><td><a href="{{ route('admin.quotations.show', $q) }}" class="font-semibold hover:text-brand-700">{{ $q->reference }}</a><span class="block text-xs text-muted">{{ $q->issue_date->format('j M Y') }}</span></td><td><x-badge :status="$q->effectiveStatus()" /></td><td class="num">{{ money($q->total, $q->currency) }}</td></tr>@empty<tr><td class="text-muted">None yet.</td></tr>@endforelse
                    </tbody></table>
                </section>
                <section class="card overflow-x-auto">
                    <h2 class="text-lg font-bold">Invoices</h2>
                    <table class="table mt-4"><tbody>
                        @forelse ($client->invoices as $i)<tr><td><a href="{{ route('admin.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a><span class="block text-xs text-muted">{{ $i->issue_date->format('j M Y') }}</span></td><td><x-badge :status="$i->effectiveStatus()" /></td><td class="num">{{ money($i->total, $i->currency) }}</td></tr>@empty<tr><td class="text-muted">None yet.</td></tr>@endforelse
                    </tbody></table>
                </section>
            </div>
            {{-- Payments only make sense once a contract has been invoiced; recorded payments always stay visible. --}}
            @if ($client->payments->isNotEmpty() || $client->invoices->contains(fn ($i) => $i->contract_id && ! in_array($i->status, ['draft', 'cancelled'])))
                <section class="card overflow-x-auto">
                    <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Payments</h2><a href="{{ route('admin.payments.create', ['client' => $client->id]) }}" class="btn btn-light btn-sm">Record a payment</a></div>
                    <table class="table mt-4"><thead><tr><th>Date</th><th>Method</th><th>For</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
                        @forelse ($client->payments as $p)<tr><td class="whitespace-nowrap"><a href="{{ route('admin.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->paid_on->format('j M Y') }}</a></td><td>{{ $p->methodName() }}</td><td class="text-xs">{{ $p->invoice?->reference ?? $p->contract?->reference ?? '—' }}</td><td><x-badge :status="$p->status" /></td><td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td></tr>
                        @empty<tr><td colspan="5" class="text-muted">No payments yet.</td></tr>@endforelse
                    </tbody></table>
                </section>
            @endif
        </div>
    </div>
</x-layout>
