<x-layout title="Sign contract">
    <x-page :title="'Sign '.$contract->reference" :eyebrow="($contract->title)" :back="[route('portal.contracts.show', $contract), 'Back to contract']">
        <x-slot:actions><a href="{{ route('documents.contract', $contract) }}" target="_blank" class="btn btn-light">Read the full contract</a></x-slot:actions>
    </x-page>
    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card text-sm lg:col-span-2">
            <h2 class="text-lg font-bold">What you are signing</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                <div><dt class="eyebrow">{{ $contract->isRecurring() ? 'Price' : 'Value' }}</dt><dd class="font-semibold">{{ $contract->amountLabel() }}</dd></div>
                <div><dt class="eyebrow">Start</dt><dd class="font-semibold">{{ $contract->start_date->format('j M Y') }}</dd></div>
                <div><dt class="eyebrow">End</dt><dd class="font-semibold">{{ $contract->end_date?->format('j M Y') ?? 'On delivery' }}</dd></div>
            </dl>
            <h3 class="mt-6 font-semibold">Scope</h3>
            <p class="mt-1 whitespace-pre-line">{{ $contract->scope }}</p>
            @if (filled($contract->terms))
                <h3 class="mt-6 font-semibold">Special terms</h3>
                <p class="mt-1 whitespace-pre-line">{{ $contract->terms }}</p>
                <p class="mt-1 text-xs text-muted">Where the special terms differ from the standard terms below, the special terms apply.</p>
            @endif
            <details class="mt-6 rounded-xl bg-paper p-4" open>
                <summary class="cursor-pointer font-semibold">Standard terms ({{ count($contract->standardTerms()) }} clauses)</summary>
                <div class="mt-4 space-y-3 text-sm">@foreach ($contract->standardTerms() as $heading => $text)<div><p class="font-semibold">{{ $loop->iteration }}. {{ $heading }}</p><p class="text-muted">{{ $text }}</p></div>@endforeach</div>
            </details>
        </section>
        <form method="post" action="{{ route('portal.contracts.sign.store', $contract) }}" class="card space-y-4 text-sm" x-data="signaturePad()" @submit="capture($event)">
            @csrf
            <h2 class="text-lg font-bold">Your signature</h2>
            <x-field label="Full name" name="signed_name" required><input id="signed_name" name="signed_name" value="{{ old('signed_name', auth()->user()->name) }}" required class="input" autocomplete="name"></x-field>
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="label mb-0" id="signature-label" x-text="mode === 'draw' ? 'Draw your signature' : 'Type your signature'">Draw your signature</p>
                    <button type="button" class="text-sm font-semibold text-brand-700 hover:underline" @click="mode = mode === 'draw' ? 'type' : 'draw'; error = ''" x-text="mode === 'draw' ? 'Type it instead' : 'Draw it instead'">Type it instead</button>
                </div>
                <div x-show="mode === 'draw'" class="mt-2">
                    <div class="relative overflow-hidden rounded-xl bg-white ring-1 ring-line" :class="error && 'ring-2 ring-red-400'">
                        <canvas x-ref="canvas" class="block h-40 w-full touch-none" role="img" aria-labelledby="signature-label"></canvas>
                        <p class="pointer-events-none absolute right-0 bottom-2 left-0 text-center text-xs text-muted" x-show="empty">Sign here with your finger, mouse or trackpad</p>
                    </div>
                    <button type="button" @click="clear()" class="btn btn-light btn-sm mt-2">Clear signature</button>
                </div>
                <div x-show="mode === 'type'" x-cloak class="mt-2">
                    <input x-model="typed" aria-labelledby="signature-label" autocomplete="name" class="input font-serif text-2xl italic" placeholder="Type your full name">
                    <p class="hint">Your typed name is saved as your signature, with the same legal effect as drawing it.</p>
                </div>
                <p class="error mt-2" x-show="error" x-text="error" x-cloak role="alert"></p>
                <input type="hidden" name="signature" x-ref="data">
                @error('signature')<p class="error">{{ $message }}</p>@enderror
            </div>
            <label class="flex items-start gap-2"><input type="checkbox" name="agree" value="1" required class="mt-1 size-4 rounded border-line"><span>I have read the contract, including the standard terms, and agree to its scope, fees and terms{{ $contract->client->isIndividual() ? '' : ' on behalf of '.$contract->client->company }}.</span></label>
            @error('agree')<p class="error">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full">Sign contract</button>
            <p class="text-xs text-muted">We record your name, the time and your IP address with the signature.</p>
        </form>
    </div>
</x-layout>
