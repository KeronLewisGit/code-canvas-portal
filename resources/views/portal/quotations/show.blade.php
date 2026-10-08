<x-layout :title="$quotation->reference">
    <x-page :title="$quotation->title" eyebrow="Quotation {{ $quotation->reference }}" :back="[route('portal.quotations.index'), 'Quotations']">
        <x-slot:actions><a href="{{ route('documents.quotation', $quotation) }}" target="_blank" class="btn btn-light">Print / PDF</a></x-slot:actions>
    </x-page>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2"><iframe src="{{ route('documents.quotation', [$quotation, 'embed' => 1]) }}" title="Quotation preview" class="h-[1150px] w-full rounded-2xl ring-1 ring-line" loading="lazy"></iframe></div>
        <div class="space-y-6">
            <section class="card text-sm">
                <h2 class="text-lg font-bold">Your answer</h2>
                <p class="mt-2"><x-badge :status="$quotation->effectiveStatus()" /></p>
                @if ($quotation->canBeAnswered())
                    <p class="mt-4 text-muted">Accepting lets us prepare the contract and deposit invoice. Nothing is charged yet.</p>
                    <form method="post" action="{{ route('portal.quotations.answer', $quotation) }}" class="mt-4 flex gap-2">@csrf @method('patch')
                        <button name="answer" value="accept" class="btn btn-primary flex-1">Accept</button>
                        <button name="answer" value="decline" class="btn btn-light flex-1" onclick="return confirm('Decline this quotation?')">Decline</button>
                    </form>
                @elseif ($quotation->accepted_at)<p class="mt-4 text-muted">Accepted on {{ $quotation->accepted_at->format('j F Y') }}. Thank you.</p>
                @elseif ($quotation->declined_at)<p class="mt-4 text-muted">Declined on {{ $quotation->declined_at->format('j F Y') }}. Email {{ config('company.email') }} if you would like a revised quote.</p>
                @elseif ($quotation->effectiveStatus() === 'expired')<p class="mt-4 text-muted">This quotation has expired. Email {{ config('company.email') }} for an updated one.</p>@endif
            </section>
        </div>
    </div>
</x-layout>
