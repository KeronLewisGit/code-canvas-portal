<x-layout :title="$contract->reference">
    <x-page :title="$contract->title" :eyebrow="($contract->reference).' · v'.($contract->version).' · '.($contract->typeName()).($contract->isRecurring() ? ' · '.$contract->cycleName() : '')" :back="[route('admin.contracts.index'), 'Contracts']">
        <x-slot:actions>
            <a href="{{ route('documents.contract', $contract) }}" target="_blank" class="btn btn-light">View PDF</a>
            <a href="{{ route('admin.contracts.edit', $contract) }}" class="btn btn-light">Edit</a>
            @php($deposit = \App\Services\ContractInvoicing::depositStatus($contract))
            @if ($deposit && ($finance['remaining_value'] ?? 0) > 0)
                <a href="{{ route('admin.invoices.create', ['contract' => $contract->id, 'kind' => 'balance']) }}" class="btn btn-light">Create balance invoice</a>
            @else
                <a href="{{ route('admin.invoices.create', ['contract' => $contract->id]) }}" class="btn btn-light">Create invoice</a>
            @endif
            <a href="{{ route('admin.payments.create', ['contract' => $contract->id]) }}" class="btn btn-light">Record a payment</a>
            {{-- The next step in the contract's life is the one main button. --}}
            @if (\App\Services\ContractLifecycle::canSendForSignature($contract) && ! $contract->sent_for_signature_at && $contract->status !== 'active')
                <form method="post" action="{{ route('admin.contracts.signature', $contract) }}" data-confirm="Email this contract to the client to review and sign? It becomes visible to them now.">@csrf @method('patch')<input type="hidden" name="action" value="request"><button class="btn btn-primary">Send for signature</button></form>
            @elseif ($contract->isSigned() && $contract->status === 'approved')
                <form method="post" action="{{ route('admin.contracts.lifecycle', $contract) }}" data-confirm="Activate this contract? The client will be emailed, and its first invoice is issued.">@csrf @method('patch')<input type="hidden" name="action" value="activate"><button class="btn btn-primary">Activate</button></form>
            @endif
        </x-slot:actions>
    </x-page>

    <div class="mb-6 flex flex-wrap items-center gap-2 text-sm">
        <x-badge :status="$contract->effectiveStatus()" />
        <x-badge :status="$contract->paymentState()" />
        @if ($contract->isSigned())<x-badge status="verified">Signed</x-badge>@elseif ($contract->awaitingSignature())<x-badge status="pending">Awaiting signature</x-badge>@endif
        @if ($contract->renewalOf)<span class="text-muted">Renewal of <a href="{{ route('admin.contracts.show', $contract->renewalOf) }}" class="font-semibold text-brand-700 hover:underline">{{ $contract->renewalOf->reference }}</a></span>@endif
        @if ($contract->renewals->isNotEmpty())<span class="text-muted">Renewed as @foreach ($contract->renewals as $r)<a href="{{ route('admin.contracts.show', $r) }}" class="font-semibold text-brand-700 hover:underline">{{ $r->reference }}</a>@if (! $loop->last), @endif @endforeach</span>@endif
        @if ($contract->confidential)<x-badge status="confidential" tone="tone-slate">Confidential</x-badge>@endif
    </div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="$contract->isRecurring() ? 'Price per '.$contract->cycle()['per'] : 'Contract value'" :value="money($contract->amount, $contract->currency)" :sub="$contract->isRecurring() ? $contract->cycleName().' · '.money($contract->dueToDate(), $contract->currency).' due to date' : $contract->statusName()" />
        <x-stat label="Verified paid" :value="money($contract->paidAmount(), $contract->currency)" :sub="status_label($contract->paymentState())" />
        <x-stat label="Balance" :value="money($contract->balance(), $contract->currency)" :sub="$contract->pendingAmount() > 0 ? money($contract->pendingAmount(), $contract->currency).' awaiting verification' : 'Nothing pending'" />
        <x-stat :label="$contract->nextDueDate() ? 'Next payment due' : 'Dates'" :value="$contract->nextDueDate()?->format('j M Y') ?? $contract->start_date->format('j M Y')" :sub="$contract->nextDueDate() ? 'Started '.$contract->start_date->format('j M Y').($contract->end_date ? ', ends '.$contract->end_date->format('j M Y') : '') : ($contract->end_date ? 'to '.$contract->end_date->format('j M Y') : 'Open-ended')" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Scope</h2>
                <p class="mt-4 whitespace-pre-line">{{ $contract->scope ?: 'No scope written yet.' }}</p>
                <p class="mt-4 text-xs text-muted">The standard Code Canvas terms print on page 2 of the contract PDF{{ $contract->standard_terms ? ', frozen as signed on '.$contract->signed_at?->format('j M Y') : '' }}. <a href="{{ route('admin.settings.terms') }}" class="font-semibold text-brand-700 hover:underline">Edit the terms</a>.</p>
            </section>
            <section class="card overflow-x-auto">
                <h2 class="text-lg font-bold">Payments</h2>
                <table class="table mt-4"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
                    @forelse ($contract->payments as $p)<tr><td class="whitespace-nowrap"><a href="{{ route('admin.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->paid_on->format('j M Y') }}</a></td><td>{{ $p->methodName() }}</td><td class="text-xs">{{ $p->reference ?: '—' }}{{ $p->invoice ? ' · '.$p->invoice->reference : '' }}</td><td><x-badge :status="$p->status" /></td><td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td></tr>
                    @empty<tr><td colspan="5" class="text-muted">No payments recorded.</td></tr>@endforelse
                </tbody></table>
            </section>
        </div>
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Lifecycle</h2>
                <ol class="mt-4 flex flex-wrap gap-1 text-xs">
                    @foreach (['draft' => 'Draft', 'approved' => 'Approved', 'active' => 'Active', 'closed' => 'Closed'] as $k => $label)
                        @php($reached = match ($k) { 'draft' => true, 'approved' => $contract->approved_at || in_array($contract->status, ['active', 'completed', 'expired', 'terminated']), 'active' => $contract->activated_at || in_array($contract->status, ['active', 'completed', 'expired', 'terminated']), 'closed' => $contract->isClosed() })
                        <li class="chip {{ $reached ? 'tone-blue' : 'tone-slate' }}">{{ $k === 'closed' && $contract->isClosed() ? $contract->statusName() : $label }}</li>
                    @endforeach
                </ol>
                <dl class="mt-4 space-y-1 text-xs text-muted">
                    @if ($contract->approved_at)<div>Approved {{ $contract->approved_at->format('j M Y') }}{{ $contract->approver ? ' by '.$contract->approver->name : '' }}</div>@endif
                    @if ($contract->activated_at)<div>Activated {{ $contract->activated_at->format('j M Y') }}</div>@endif
                    @if ($contract->terminated_at)<div>Terminated {{ $contract->terminated_at->format('j M Y') }}: {{ $contract->termination_reason }}</div>@endif
                    <div>Payment terms {{ $contract->payment_terms_days }} days{{ $contract->notice_period_days ? ' · notice '.$contract->notice_period_days.' days' : '' }}{{ $contract->auto_renew ? ' · auto-renews' : '' }}</div>
                    @if ($contract->noticeDeadline())<div>Notice deadline for next cycle: <span class="font-semibold text-ink">{{ $contract->noticeDeadline()->format('j M Y') }}</span></div>@endif
                </dl>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($actions as $action)
                        @if ($action === 'terminate') @continue @endif
                        <form method="post" action="{{ route('admin.contracts.lifecycle', $contract) }}" data-confirm="{{ ['approve' => 'Approve this contract? It stays hidden from the client until you send it for signature.', 'activate' => 'Activate this contract? The client will be emailed, and if it is signed its first invoice is issued.', 'cancel' => 'Cancel this contract? The client will be emailed.', 'complete' => 'Mark this contract completed? The client will be emailed.', 'reopen' => 'Move this contract back to draft?'][$action] ?? '' }}">@csrf @method('patch')<input type="hidden" name="action" value="{{ $action }}"><button class="btn {{ in_array($action, ['approve', 'activate']) ? 'btn-primary' : 'btn-light' }} btn-sm">{{ ['approve' => 'Approve', 'activate' => 'Activate', 'complete' => 'Mark completed', 'cancel' => 'Cancel contract', 'reopen' => 'Back to draft'][$action] }}</button></form>
                    @endforeach
                    @if (($contract->isLive() || $contract->effectiveStatus() === 'expired') && $contract->renewals->isEmpty())
                        <form method="post" action="{{ route('admin.contracts.renew', $contract) }}">@csrf<button class="btn btn-light btn-sm">Draft renewal</button></form>
                    @endif
                </div>
                @if (in_array('terminate', $actions))
                    <details class="mt-4 rounded-xl bg-paper p-3" @if ($errors->has('reason') || $errors->has('terminated_at')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold text-red-700">Terminate contract</summary>
                        <form method="post" action="{{ route('admin.contracts.lifecycle', $contract) }}" class="mt-4 space-y-2">@csrf @method('patch')<input type="hidden" name="action" value="terminate">
                            <x-field label="Effective date" name="terminated_at"><input id="terminated_at" name="terminated_at" type="date" value="{{ now()->format('Y-m-d') }}" class="input"></x-field>
                            <x-field label="Reason" name="reason" required><textarea id="reason" name="reason" rows="2" required class="input"></textarea></x-field>
                            <button class="btn btn-danger btn-sm" data-confirm="Terminate this contract? Future billing stops and the record is kept.">Confirm termination</button>
                        </form>
                    </details>
                @endif
                <p class="mt-4 text-xs text-muted">Approve once terms are agreed internally, send for signature, then activate. Expired contracts are marked automatically after the end date.</p>
            </section>
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Financials</h2>
                @php($refundable = \App\Services\Adjustments::refundableOnContract($contract))
                @if ($refundable > 0)
                    <div class="mt-3 rounded-xl bg-brand-50 px-3 py-2 text-sm text-brand-900">
                        <p class="font-semibold">Credit on account: {{ money($refundable, $contract->currency) }} <span class="font-normal">(applied to the next invoice unless refunded)</span></p>
                        @php($refundUrl = route('admin.contracts.refund', $contract))
                        <details class="mt-1" @if ($errors->has('refund_amount')) open @endif><summary class="cursor-pointer font-semibold">Refund it</summary>
                        <form method="post" action="{{ $refundUrl }}" class="mt-2 space-y-2" data-confirm="Record this refund and email the client?">@csrf
                            <div class="grid grid-cols-2 gap-2">
                                <x-field label="Amount" name="refund_amount" required><input id="refund_amount" name="refund_amount" type="number" step="0.01" min="0.01" max="{{ $refundable }}" value="{{ old('refund_amount', $refundable) }}" required class="input"></x-field>
                                <x-field label="Paid back by" name="refund_method" required><select id="refund_method" name="refund_method" class="input"><option>bank transfer</option><option>cheque</option><option>cash</option></select></x-field>
                            </div>
                            <x-field label="Note (optional)" name="refund_note"><input id="refund_note" name="refund_note" value="{{ old('refund_note') }}" class="input" placeholder="e.g. transfer reference"></x-field>
                            <button class="btn btn-light btn-sm">Record refund</button>
                        </form>
                        </details>
                    </div>
                @endif
                @if (! $contract->isRecurring() && in_array($contract->status, ['active', 'completed']) && ($finance['remaining_value'] ?? 0) > 0 && $contract->invoices->where('status', '!=', 'cancelled')->isNotEmpty())
                    <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900"><p class="font-semibold">{{ money($finance['remaining_value'], $contract->currency) }} not invoiced yet.</p><a href="{{ route('admin.invoices.create', ['contract' => $contract->id, 'kind' => 'balance']) }}" class="btn btn-light btn-sm mt-2">Create balance invoice</a></div>
                @endif
                @if ($deposit)<p class="mt-3 rounded-xl px-3 py-2 text-sm font-semibold {{ $deposit['paid'] ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900' }}">{{ $deposit['paid'] ? 'Deposit received' : 'Deposit outstanding: '.money($deposit['balance'], $contract->currency) }} <a href="{{ route('admin.invoices.show', $deposit['invoice']) }}" class="font-normal underline">{{ $deposit['invoice']->reference }}</a></p>@endif
                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2">
                    <div><dt class="eyebrow">Contracted</dt><dd class="font-semibold tabular-nums">{{ $finance['contracted_value'] === null ? 'Open-ended' : money($finance['contracted_value'], $contract->currency) }}</dd></div>
                    <div><dt class="eyebrow">Remaining to bill</dt><dd class="font-semibold tabular-nums">{{ $finance['remaining_value'] === null ? '—' : money($finance['remaining_value'], $contract->currency) }}</dd></div>
                    <div><dt class="eyebrow">Invoiced</dt><dd class="font-semibold tabular-nums">{{ money($finance['invoiced'], $contract->currency) }}</dd></div>
                    <div><dt class="eyebrow">Collected</dt><dd class="font-semibold tabular-nums">{{ money($finance['collected'], $contract->currency) }}</dd></div>
                    <div><dt class="eyebrow">Receivable</dt><dd class="font-semibold tabular-nums">{{ money($finance['receivable'], $contract->currency) }}</dd></div>
                    <div><dt class="eyebrow">Overdue</dt><dd class="font-semibold tabular-nums {{ $finance['overdue'] > 0 ? 'text-red-700' : '' }}">{{ money($finance['overdue'], $contract->currency) }}</dd></div>
                    <div><dt class="eyebrow">Monthly recurring</dt><dd class="font-semibold tabular-nums">{{ money($finance['monthly_recurring'], $contract->currency) }}</dd></div>
                    <div><dt class="eyebrow">Next 12 months</dt><dd class="font-semibold tabular-nums">{{ money($finance['forecast_12m'], $contract->currency) }}@if ($finance['cancellable_12m'] > 0)<span class="block text-xs font-normal text-muted">{{ money($finance['cancellable_12m'], $contract->currency) }} cancellable</span>@endif</dd></div>
                </dl>
                <p class="mt-4 text-xs text-muted">Invoiced comes from invoices, collected from verified payments. Nothing here is stored twice.</p>
            </section>
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Client</h2>
                <p class="mt-2"><a href="{{ route('admin.clients.show', $contract->client) }}" class="font-semibold hover:text-brand-700">{{ $contract->client->company }}</a></p>
                <p class="text-muted">{{ $contract->client->contact_name }} · {{ $contract->client->email }}</p>
            </section>
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Signature</h2>
                @if ($contract->isSignedInPortal())
                    <p class="mt-2"><x-badge status="verified">Signed in portal</x-badge></p>
                    @if ($sig = $contract->signatureDataUri())<img src="{{ $sig }}" alt="Client signature" class="mt-4 h-16 w-auto rounded-lg bg-white ring-1 ring-line">@endif
                    <p class="mt-2">{{ $contract->signed_name }} · {{ $contract->signed_at->format('j M Y, g:ia') }}</p>
                    <p class="text-xs text-muted">IP {{ $contract->signed_ip }} · login {{ $contract->signer?->email }}</p>
                    @unless ($contract->isClosed())<form method="post" action="{{ route('admin.contracts.signature', $contract) }}" class="mt-3" data-confirm="Clear the signature? The client will need to sign again.">@csrf @method('patch')<input type="hidden" name="action" value="clear"><button class="btn-link-danger">Clear signature</button></form>@endunless
                @elseif ($contract->isSigned())
                    <p class="mt-2"><x-badge status="verified">Signed copy uploaded</x-badge></p><p class="mt-2 text-muted">Signed {{ $contract->signed_at->format('j M Y') }}.</p>
                @elseif ($contract->awaitingSignature())
                    <p class="mt-2"><x-badge status="pending">Awaiting client</x-badge></p><p class="mt-2 text-muted">Sent {{ $contract->sent_for_signature_at->diffForHumans() }}. The client sees a "Sign now" banner in their portal.</p>
                    <div class="mt-4 flex flex-wrap gap-2"><form method="post" action="{{ route('admin.contracts.remind', $contract) }}" data-confirm="Email {{ $contract->client->company }} a reminder to sign?{{ $contract->reminded_at ? ' The last one went '.$contract->reminded_at->diffForHumans().'.' : '' }}">@csrf<button class="btn btn-primary btn-sm">Send reminder</button></form><form method="post" action="{{ route('admin.contracts.signature', $contract) }}">@csrf @method('patch')<input type="hidden" name="action" value="withdraw"><button class="btn btn-light btn-sm" data-confirm="Withdraw the signature request? The client will no longer be able to sign until you send it again.">Withdraw request</button></form></div>
                    @if ($contract->reminded_at)<p class="mt-2 text-xs text-muted">Last reminder {{ $contract->reminded_at->diffForHumans() }}.</p>@endif
                @else
                    <p class="mt-2 text-muted">Not sent for signature. The client will be able to read the contract PDF and sign it electronically in their portal.</p>
                    <form method="post" action="{{ route('admin.contracts.signature', $contract) }}" class="mt-3" data-confirm="Email this contract to the client to review and sign? It becomes visible to them now.">@csrf @method('patch')<input type="hidden" name="action" value="request"><button class="btn btn-primary btn-sm">Send for signature</button></form>
                    @if ($contract->status === 'draft')<p class="mt-2 text-xs text-muted">Sending approves the draft so the client can see it; it becomes Active when they sign.</p>@endif
                @endif
                <div class="mt-4 border-t border-line pt-3">
                    <p class="eyebrow">Uploaded copy</p>
                    @if ($contract->file_path)<a href="{{ route('files.contract', $contract) }}" class="font-semibold text-brand-700 hover:underline">Download</a>@else<p class="text-muted">None. Upload a scanned signed copy from Edit if you signed on paper.</p>@endif
                </div>
            </section>
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Invoices</h2>
                <ul class="mt-4 space-y-2">
                    @forelse ($contract->invoices as $i)<li class="flex items-center justify-between gap-2"><span><a href="{{ route('admin.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a> <x-badge :status="$i->effectiveStatus()" /></span><span class="tabular-nums">{{ money($i->total, $i->currency) }}</span></li>@empty<li class="text-muted">No invoices for this contract.</li>@endforelse
                </ul>
                @if ($contract->quotations->isNotEmpty())<p class="mt-4 text-xs text-muted">From quotation {{ $contract->quotations->pluck('reference')->implode(', ') }}</p>@endif
            </section>
            <section class="card text-sm">
                <x-card-header title="History"><a href="{{ route('admin.contracts.activity', $contract) }}" class="text-sm font-semibold text-brand-700 hover:underline">Full audit log</a></x-card-header>
                @if ($contract->versions->isNotEmpty())
                    <p class="eyebrow mt-3">Previous versions</p>
                    <ul class="mt-1 space-y-1">@foreach ($contract->versions as $v)<li><a href="{{ route('admin.contracts.version', [$contract, $v->version]) }}" class="font-semibold text-brand-700 hover:underline">Version {{ $v->version }}</a> <span class="text-muted">· {{ $v->created_at->format('j M Y') }}{{ $v->reason ? ' · '.$v->reason : '' }}</span></li>@endforeach</ul>
                @endif
                <p class="eyebrow mt-3">Recent activity</p>
                <ul class="mt-1 space-y-1 text-xs text-muted">
                    @forelse ($activity as $a)<li><span class="font-semibold text-ink">{{ ucfirst(str_replace('_', ' ', $a->action)) }}</span> · {{ $a->user?->name ?? 'System' }} · {{ $a->created_at->format('j M Y, g:ia') }}</li>@empty<li>No activity recorded yet.</li>@endforelse
                </ul>
            </section>
            @if (! $contract->isSigned() && ! $contract->payments()->exists() && ! $contract->invoices()->exists())
                <form method="post" action="{{ route('admin.contracts.destroy', $contract) }}" data-confirm="Delete this draft contract? This cannot be undone." class="text-right">@csrf @method('delete')<button class="btn-link-danger">Delete contract</button></form>
            @else
                <p class="text-right text-xs text-muted">Signed, invoiced or paid contracts cannot be deleted. Terminate or cancel instead.</p>
            @endif
        </div>
    </div>
</x-layout>
