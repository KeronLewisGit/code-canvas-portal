<x-document-layout :reference="$contract->reference" :title="'Contract '.$contract->reference" :back="auth()->user()->isAdmin() ? route('admin.contracts.show', $contract) : route('portal.contracts.show', $contract)">
    @php($company = config('company'))
    <article class="doc">
        <header class="flex items-start justify-between gap-6 border-b border-line pb-5">
            <div><p class="doc-title">AGREEMENT</p><p class="mt-2 font-semibold">Contract# {{ $contract->reference }} · v{{ $contract->version }}</p><p class="small muted">{{ $contract->typeName() }} · dated {{ $contract->created_at->format('j F Y') }}</p></div>
            <div class="text-right"><x-logo :full="true" class="ml-auto h-20 w-auto" /><p class="small muted mt-2">{{ $company['name'] }}<br>{{ $company['address'] }}<br>{{ $company['email'] }}<br>{{ $company['phone'] }}</p></div>
        </header>

        <h1 class="mt-6 text-xl font-semibold">{{ $contract->title }}</h1>
        <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
            <div><p class="eyebrow">Between</p><p class="font-semibold">{{ $company['name'] }}</p><p class="text-muted">("Code Canvas")</p></div>
            <div><p class="eyebrow">And</p><p class="font-semibold">{{ $contract->client->company }}</p><p class="text-muted">@unless ($contract->client->isIndividual()){{ $contract->client->contact_name }} · @endunless{{ $contract->client->email }}@if ($contract->client->address)<br>{{ $contract->client->address }}@endif<br>("the Client")</p></div>
        </div>

        <dl class="mt-6 grid grid-cols-3 gap-4 rounded-xl bg-paper p-4 text-sm avoid-break">
            <div><dt class="eyebrow">Start date</dt><dd class="font-semibold">{{ $contract->start_date->format('j F Y') }}</dd></div>
            <div><dt class="eyebrow">End date</dt><dd class="font-semibold">{{ $contract->end_date?->format('j F Y') ?? 'Until the work is delivered' }}</dd></div>
            <div><dt class="eyebrow">{{ $contract->isRecurring() ? 'Fee' : 'Contract value' }}</dt><dd class="font-semibold">{{ $contract->amountLabel() }}</dd>@if ($contract->isRecurring())<dd class="text-xs text-muted">Billed {{ strtolower($contract->cycleName()) }}{{ $contract->notice_period_days ? ' · '.$contract->notice_period_days.' days notice' : '' }}</dd>@endif<dd class="text-xs text-muted">Invoices payable within {{ $contract->payment_terms_days }} days · v{{ $contract->version }}</dd></div>
        </dl>

        <h2 class="mt-8 text-lg">1. Scope of work</h2>
        <p class="whitespace-pre-line text-sm">{{ $contract->scope ?: 'As described in the accepted quotation.' }}</p>

        <h2 class="mt-6 text-lg">2. Fees and payment</h2>
        <p class="text-sm">@if ($contract->isRecurring())The Client agrees to pay {{ $company['name'] }} {{ money($contract->amount, $contract->currency) }} ({{ $contract->currency }}) per {{ $contract->cycle()['per'] }}, starting {{ $contract->start_date->format('j F Y') }} and continuing {{ $contract->end_date ? 'until '.$contract->end_date->format('j F Y') : 'until either party ends this agreement in writing' }}. @if (filled($contract->terms))Invoicing and payment timing are set out in the special terms in section 3. Payment is made @else Each {{ $contract->cycle()['per'] }} is payable in advance, @endif by bank transfer, cheque or cash, against invoices issued through the client portal.@else The Client agrees to pay {{ $company['name'] }} {{ money($contract->amount, $contract->currency) }} ({{ $contract->currency }}) for the work described above, by bank transfer, cheque or cash, against invoices issued through the client portal.@endif Payments are confirmed once verified by {{ $company['short'] }}.</p>

        <h2 class="mt-6 text-lg">3. Terms</h2>
        @if (filled($contract->terms))
            <h3 class="mt-2 text-sm font-semibold">Special terms</h3>
            <p class="whitespace-pre-line text-sm">{{ $contract->terms }}</p>
            <p class="mt-2 text-sm">The standard terms of {{ $company['name'] }} set out at the end of this document also form part of this agreement. Where the special terms above differ from the standard terms or from section 2, the special terms apply.</p>
        @else
            <p class="text-sm">The standard terms of {{ $company['name'] }} set out at the end of this document form part of this agreement.</p>
        @endif

        <h2 class="mt-6 text-lg">4. Agreement</h2>
        <p class="text-sm">By signing below, both parties agree to the scope, fees and terms set out in this document. An electronic signature made through the {{ $company['short'] }} client portal has the same effect as a handwritten signature.</p>

        <div class="mt-8 grid grid-cols-2 gap-8 avoid-break">
            <div class="text-sm">
                <div class="flex h-20 items-end"><p class="font-display text-xl italic">{{ $contract->companySignatoryName() }}</p></div>
                <div class="mt-1 border-t-2 border-ink pt-2">
                    <p class="font-semibold">{{ $contract->companySignatoryName() }}</p>
                    <p class="muted">For {{ $company['name'] }}</p>
                    <p class="small muted">{{ $contract->created_at->format('j F Y') }}</p>
                </div>
            </div>
            <div class="text-sm">
                <div class="flex h-20 items-end">
                    @if ($contract->isSignedInPortal() && ($sig = $contract->signatureDataUri()))<img src="{{ $sig }}" alt="Client signature" class="max-h-20 w-auto">@endif
                </div>
                <div class="mt-1 border-t-2 border-ink pt-2">
                    @if ($contract->isSignedInPortal())
                        <p class="font-semibold">{{ $contract->signed_name }}</p>
                        @unless ($contract->client->isIndividual())<p class="muted">For {{ $contract->client->company }}</p>@endunless
                        <p class="small muted">Signed electronically {{ $contract->signed_at->format('j F Y \a\t g:ia') }} ({{ config('app.timezone') }}) · IP {{ $contract->signed_ip }}</p>
                    @elseif ($contract->isSigned())
                        <p class="font-semibold">{{ $contract->clientSignatoryName() }}</p>
                        @unless ($contract->client->isIndividual())<p class="muted">For {{ $contract->client->company }}</p>@endunless
                        <p class="small muted">Signed {{ $contract->signed_at->format('j F Y') }} (signed copy on file)</p>
                    @else
                        <p class="font-semibold">{{ $contract->clientSignatoryName() }}</p>
                        @unless ($contract->client->isIndividual())<p class="muted">For {{ $contract->client->company }}</p>@endunless
                        <p class="small muted">Not yet signed</p>
                    @endif
                </div>
            </div>
        </div>
        <section class="doc-terms">
            <p class="eyebrow">Agreement {{ $contract->reference }} · v{{ $contract->version }}</p>
            <h2 class="mt-1 text-lg font-semibold">Standard terms</h2>
            @foreach ($contract->standardTerms() as $heading => $text)<div><h3>{{ $loop->iteration }}. {{ $heading }}</h3><p>{{ $text }}</p></div>@endforeach
        </section>
    </article>
</x-document-layout>
