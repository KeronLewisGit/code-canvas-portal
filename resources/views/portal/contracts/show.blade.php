<x-layout :title="$contract->reference">
    <x-page :title="$contract->title" :eyebrow="($contract->reference).' · '.($contract->typeName()).($contract->isRecurring() ? ' · '.$contract->cycleName() : '')" :back="[route('portal.contracts.index'), 'Contracts']">
        <x-slot:actions>
            <a href="{{ route('documents.contract', $contract) }}" target="_blank" class="btn btn-light">{{ $contract->isSigned() ? 'Download signed contract' : 'View PDF' }}</a>
            @if ($contract->acceptsPayments() && ! \App\Services\ContractInvoicing::depositStatus($contract))<a href="{{ route('portal.payments.create', ['contract' => $contract->id]) }}" class="btn btn-primary">Submit a payment</a>@endif
        </x-slot:actions>
    </x-page>
    @if ($contract->awaitingSignature())<div class="callout mb-6"><p><span class="font-semibold">This contract is waiting for your signature.</span> Read it, then sign in the portal in under a minute.</p><a href="{{ route('portal.contracts.sign', $contract) }}" class="btn btn-primary">Sign now</a></div>@endif
    @php($deposit = \App\Services\ContractInvoicing::depositStatus($contract))
    @if (($credit = $contract->unallocatedCredit()) > 0)
        <p class="mb-6 rounded-xl bg-brand-50 px-4 py-3 text-sm font-semibold text-brand-900 ring-1 ring-brand-100">You have {{ money($credit, $contract->currency) }} in credit on this contract. It is applied to your next invoice automatically.</p>
    @endif
    @if ($deposit && ! $deposit['paid'])
        <div class="callout mb-6"><p><span class="font-semibold">Deposit of {{ money($deposit['balance'], $contract->currency) }} outstanding.</span> Work usually starts once it is received.</p>@if ($deposit['invoice']->acceptsPayments())<a href="{{ route('portal.payments.create', ['invoice' => $deposit['invoice']->id]) }}" class="btn btn-primary">Pay the deposit</a>@endif</div>
    @elseif ($deposit)
        <p class="mb-6 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 ring-1 ring-emerald-200">Deposit received. Thank you.</p>
    @endif
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="$contract->isRecurring() ? 'Price per '.$contract->cycle()['per'] : 'Contract value'" :value="money($contract->amount, $contract->currency)" :sub="$contract->isRecurring() ? $contract->cycleName().' · '.money($contract->dueToDate(), $contract->currency).' due to date' : $contract->statusName()" />
        <x-stat label="Paid" :value="money($contract->paidAmount(), $contract->currency)" :sub="status_label($contract->paymentState())" />
        <x-stat label="Balance" :value="money($contract->balance(), $contract->currency)" :sub="$contract->pendingAmount() > 0 ? money($contract->pendingAmount(), $contract->currency).' being verified' : 'Nothing pending'" />
        <x-stat :label="$contract->nextDueDate() ? 'Next payment due' : 'Dates'" :value="$contract->nextDueDate()?->format('j M Y') ?? $contract->start_date->format('j M Y')" :sub="$contract->nextDueDate() ? 'Started '.$contract->start_date->format('j M Y').($contract->end_date ? ', ends '.$contract->end_date->format('j M Y') : '') : ($contract->end_date ? 'to '.$contract->end_date->format('j M Y') : 'Open-ended')" />
    </div>
    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Scope of work</h2>
                <p class="mt-4 whitespace-pre-line">{{ $contract->scope ?: 'Scope to be confirmed.' }}</p>
                @if (filled($contract->terms))
                    <h3 class="mt-6 font-bold">Special terms</h3>
                    <p class="mt-2 whitespace-pre-line">{{ $contract->terms }}</p>
                    <p class="mt-2 text-xs text-muted">Where the special terms differ from the standard terms below, the special terms apply.</p>
                @endif
                <details class="mt-6"><summary class="cursor-pointer font-bold">Standard terms</summary><div class="mt-4 space-y-3">@foreach ($contract->standardTerms() as $heading => $text)<div><p class="font-semibold">{{ $loop->iteration }}. {{ $heading }}</p><p class="text-muted">{{ $text }}</p></div>@endforeach</div></details>
            </section>
            <section class="card overflow-x-auto">
                <h2 class="text-lg font-bold">Payments on this contract</h2>
                <table class="table mt-4"><thead><tr><th>Date</th><th>Method</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
                    @forelse ($contract->payments as $p)<tr><td class="whitespace-nowrap"><a href="{{ route('portal.payments.show', $p) }}" class="font-semibold hover:text-brand-700">{{ $p->paid_on->format('j M Y') }}</a></td><td>{{ $p->methodName() }}</td><td><x-badge :status="$p->status" /></td><td class="num font-semibold">{{ money($p->amount, $p->currency) }}</td></tr>
                    @empty<tr><td colspan="4" class="text-muted">No payments yet.</td></tr>@endforelse
                </tbody></table>
            </section>
        </div>
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Invoices</h2>
                <ul class="mt-4 space-y-2">
                    @forelse ($contract->invoices as $i)<li class="flex items-center justify-between gap-2"><span><a href="{{ route('portal.invoices.show', $i) }}" class="font-semibold hover:text-brand-700">{{ $i->reference }}</a> <x-badge :status="$i->effectiveStatus()" /></span><span class="tabular-nums">{{ money($i->total, $i->currency) }}</span></li>@empty<li class="text-muted">No invoices yet.</li>@endforelse
                </ul>
            </section>
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Signature</h2>
                @if ($contract->isSignedInPortal())<p class="mt-2"><x-badge status="verified">Signed</x-badge></p><p class="mt-2 text-muted">Signed by {{ $contract->signed_name }} on {{ $contract->signed_at->format('j F Y') }}.</p>
                @elseif ($contract->isSigned())<p class="mt-2"><x-badge status="verified">Signed</x-badge></p><p class="mt-2 text-muted">Signed copy on file, {{ $contract->signed_at->format('j F Y') }}.</p>
                @elseif ($contract->awaitingSignature())<p class="mt-2"><x-badge status="pending">Awaiting your signature</x-badge></p>
                @else<p class="mt-2 text-muted">Not sent for signature yet.</p>@endif
                @if ($contract->file_path)<a href="{{ route('files.contract', $contract) }}" class="mt-4 btn btn-light">Download uploaded copy</a>@endif
            </section>
        </div>
    </div>
</x-layout>
