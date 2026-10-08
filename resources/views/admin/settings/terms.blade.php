<x-layout title="Settings">
    <x-page title="Settings" eyebrow="Standard terms and rates printed on every contract, invoice and quotation">
        <x-slot:actions><a href="{{ route('documents.contract', ['contract' => \App\Models\Contract::query()->latest()->value('id') ?? 0]) }}" target="_blank" class="btn btn-light" @if (! \App\Models\Contract::exists()) hidden @endif>Preview on latest contract</a></x-slot:actions>
    </x-page>

    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <form method="post" action="{{ route('admin.settings.rates') }}" class="card space-y-4 text-sm" onsubmit="return true">
            @csrf @method('put')
            <h2 class="text-lg">Rates used in the terms</h2>
            <x-field label="Deposit before work starts" name="deposit_percent" required hint="Fills {deposit} in the contract terms."><div class="flex items-center gap-2"><input id="deposit_percent" name="deposit_percent" type="number" inputmode="decimal" step="1" min="0" max="100" value="{{ old('deposit_percent', $deposit) }}" required class="input max-w-28"><span class="text-muted">%</span></div></x-field>
            <x-field label="Late payment fee per month" name="late_fee_monthly_percent" required hint="Fills {late_fee}."><div class="flex items-center gap-2"><input id="late_fee_monthly_percent" name="late_fee_monthly_percent" type="number" inputmode="decimal" step="0.1" min="0" max="20" value="{{ old('late_fee_monthly_percent', $lateFee) }}" required class="input max-w-28"><span class="text-muted">%</span></div></x-field>
            <button class="btn btn-primary">Save rates</button>
        </form>
        <div class="card text-sm lg:col-span-2">
            <h2 class="text-lg">How this works</h2>
            <ul class="mt-4 list-disc space-y-1 pl-5 text-muted">
                <li>Each group below is the full set of clauses printed on that document type. Edit the wording, add or remove clauses, and reorder them with the arrows.</li>
                <li>Contract clauses can use placeholders that fill in per contract: <code class="rounded bg-paper px-1">{deposit}</code> <code class="rounded bg-paper px-1">{payment_days}</code> <code class="rounded bg-paper px-1">{late_fee}</code> <code class="rounded bg-paper px-1">{notice}</code> <code class="rounded bg-paper px-1">{cycle}</code> <code class="rounded bg-paper px-1">{company}</code> <code class="rounded bg-paper px-1">{short}</code>. A clause headed exactly "Recurring services" is printed only on recurring contracts.</li>
                <li><span class="font-semibold text-ink">Signed contracts are never changed.</span> The terms are frozen onto a contract at the moment the client signs it, so edits here apply only to contracts signed afterwards.</li>
                <li>"Reset to defaults" restores the built-in wording from the code.</li>
            </ul>
        </div>
    </div>

    @foreach ($groups as $group)
        <form method="post" action="{{ route('admin.settings.terms.update') }}" class="card mb-6" x-data="clauseList(@js($group['clauses']))">
            @csrf @method('put')
            <input type="hidden" name="group" value="{{ $group['key'] }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-lg">{{ $group['label'] }}</h2><p class="text-sm text-muted"><span x-text="clauses.length"></span> clauses{{ $group['updated'] ? ' · last saved '.$group['updated']->format('j M Y, g:ia') : ' · built-in defaults' }}</p></div>
                <button type="button" @click="add()" class="btn btn-light btn-sm">Add clause</button>
            </div>
            <ol class="mt-4 space-y-3">
                <template x-for="(c, i) in clauses" :key="c.id">
                    <li class="rounded-xl bg-paper p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="w-6 text-sm font-semibold text-muted" x-text="i + 1 + '.'"></span>
                            <input :name="`clauses[${i}][heading]`" x-model="c.heading" placeholder="Heading" required class="input flex-1 bg-white" :aria-label="`Clause ${i + 1} heading`">
                            <span class="flex gap-1">
                                <button type="button" @click="move(i, -1)" :disabled="i === 0" class="btn btn-light btn-sm" :aria-label="`Move clause ${i + 1} up`">&uarr;</button>
                                <button type="button" @click="move(i, 1)" :disabled="i === clauses.length - 1" class="btn btn-light btn-sm" :aria-label="`Move clause ${i + 1} down`">&darr;</button>
                                <button type="button" @click="remove(i)" class="btn-link-danger px-2" :aria-label="`Remove clause ${i + 1}`">Remove</button>
                            </span>
                        </div>
                        <textarea :name="`clauses[${i}][text]`" x-model="c.text" rows="3" required placeholder="Clause wording" class="input mt-2 bg-white" :aria-label="`Clause ${i + 1} wording`"></textarea>
                    </li>
                </template>
            </ol>
            <div class="mt-4 flex items-center justify-between gap-3">
                <button class="btn btn-primary">Save {{ strtolower($group['label']) }}</button>
                <button type="submit" form="reset-{{ $group['key'] }}" class="btn-link-danger" onclick="return confirm('Replace your edited {{ strtolower($group['label']) }} with the built-in defaults?')">Reset to defaults</button>
            </div>
        </form>
        <form id="reset-{{ $group['key'] }}" method="post" action="{{ route('admin.settings.terms.reset') }}" class="hidden">@csrf @method('delete')<input type="hidden" name="group" value="{{ $group['key'] }}"></form>
    @endforeach
</x-layout>
