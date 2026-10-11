<x-layout :title="$quotation->reference">
    <x-page :title="$quotation->title" :eyebrow="($quotation->reference).' · '.($quotation->client->company)" :back="[route('admin.quotations.index'), 'Quotations']">
        <x-slot:actions>
            <a href="{{ route('documents.quotation', $quotation) }}" target="_blank" class="btn btn-light">View PDF</a>
            @unless ($quotation->status === 'accepted')<a href="{{ route('admin.quotations.edit', $quotation) }}" class="btn btn-light">Edit</a>@endunless
            @if ($quotation->status === 'draft')<form method="post" action="{{ route('admin.quotations.status', $quotation) }}" data-confirm="Email this quotation to the client now? They will be able to review, accept or decline it.">@csrf @method('patch')<input type="hidden" name="status" value="sent"><button class="btn btn-primary">Send to client</button></form>@endif
            @if ($quotation->canBeAnswered())<form method="post" action="{{ route('admin.quotations.remind', $quotation) }}" data-confirm="Email {{ $quotation->client->company }} a reminder about this quotation?{{ $quotation->reminded_at ? ' The last one went '.$quotation->reminded_at->diffForHumans().'.' : '' }}">@csrf<button class="btn btn-light">Send reminder</button></form>@endif
            @if ($quotation->status === 'accepted' && ! $quotation->contract_id)<form method="post" action="{{ route('admin.quotations.contract', $quotation) }}">@csrf<button class="btn btn-primary">Start contract</button></form>@endif
            @if ($quotation->status === 'accepted' && ! $quotation->contract_id)<form method="post" action="{{ route('admin.quotations.invoice', $quotation) }}">@csrf<button class="btn btn-light">Create invoice</button></form>@endif
        </x-slot:actions>
    </x-page>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2"><iframe src="{{ route('documents.quotation', [$quotation, 'embed' => 1]) }}" title="Quotation preview" class="h-[75vh] min-h-[28rem] w-full rounded-2xl lg:h-[1150px] ring-1 ring-line" loading="lazy"></iframe></div>
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Status</h2>
                <p class="mt-2"><x-badge :status="$quotation->effectiveStatus()" /></p>
                <dl class="mt-4 space-y-1 text-xs text-muted">
                    @if ($quotation->sent_at)<div>Sent {{ $quotation->sent_at->format('j M Y') }}{{ $quotation->reminded_at ? ' · reminded '.$quotation->reminded_at->diffForHumans() : '' }}</div>@endif
                    @if ($quotation->accepted_at)<div>Accepted by client {{ $quotation->accepted_at->format('j M Y, g:ia') }}</div>@endif
                    @if ($quotation->declined_at)<div>Declined {{ $quotation->declined_at->format('j M Y') }}</div>@endif
                    @if ($quotation->valid_until)<div>Valid until {{ $quotation->valid_until->format('j M Y') }}</div>@endif
                </dl>
                <form method="post" action="{{ route('admin.quotations.status', $quotation) }}" class="mt-4 flex gap-2" onsubmit="return !['declined', 'expired'].includes(this.status.value) || confirm('Mark this quotation ' + this.status.value + '? The client can no longer accept it.')">@csrf @method('patch')
                    <select name="status" aria-label="Quotation status" class="input">@foreach (config('company.quotation_statuses') as $k => $v)<option value="{{ $k }}" @selected($quotation->status === $k)>{{ $v }}</option>@endforeach</select>
                    <button class="btn btn-light">Update</button>
                </form>
                <p class="mt-4 text-xs text-muted">Clients see sent, accepted, declined and expired quotations in their portal and can accept or decline a sent one.</p>
            </section>
            @if (! $quotation->contract_id && in_array($quotation->status, ['draft', 'sent']))
                <section class="card text-sm">
                    <h2 class="text-lg font-bold">When the client accepts</h2>
                    <p class="mt-2 font-semibold">{{ config('company.contract_types.'.$quotation->contract_type, 'Type to decide') }} · {{ config('company.billing_cycles.'.$quotation->billing_cycle.'.label', 'billing to decide') }}{{ $quotation->contract_term_months ? ' · '.$quotation->contract_term_months.'-month term' : '' }}</p>
                    <p class="mt-1 text-muted">{{ $quotation->auto_send_contract ? 'The contract is generated and sent to the client to sign straight away.' : 'The contract is drafted for you to review and send.' }} <a href="{{ route('admin.quotations.edit', $quotation) }}" class="font-semibold text-brand-700">Change</a></p>
                </section>
            @endif
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Linked</h2>
                <ul class="mt-2 space-y-1">
                    @if ($quotation->contract)<li>Contract <a href="{{ route('admin.contracts.show', $quotation->contract) }}" class="font-semibold text-brand-700">{{ $quotation->contract->reference }}</a></li>@endif
                    @foreach ($quotation->invoices as $i)<li>Invoice <a href="{{ route('admin.invoices.show', $i) }}" class="font-semibold text-brand-700">{{ $i->reference }}</a> <x-badge :status="$i->effectiveStatus()" /></li>@endforeach
                    @if (! $quotation->contract && $quotation->invoices->isEmpty())<li class="text-muted">Nothing linked yet.</li>@endif
                </ul>
            </section>
            <form method="post" action="{{ route('admin.quotations.destroy', $quotation) }}" data-confirm="Delete this quotation?" class="text-right">@csrf @method('delete')<button class="btn-link-danger">Delete quotation</button></form>
        </div>
    </div>
</x-layout>
