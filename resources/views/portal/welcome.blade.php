@php($steps = [
    ['quotations', 'Review quotations', 'When we quote for your project it appears under Quotations. Read it, download a PDF, then accept or decline in one click.'],
    ['contracts', 'Sign your contract', 'Once you accept a quotation we prepare the contract. Read it in full and sign online with your finger, mouse or trackpad.'],
    ['invoices', 'See what is due', 'After signing, your invoice appears under Invoices with the amount and due date. A deposit is usually what gets work started.'],
    ['payments', 'Tell us you have paid', 'Paid by bank transfer or another method? Submit the details and proof of payment, and we confirm it within one working day.'],
])
<x-layout title="Welcome" :plain="true" :wide="true">
    <div class="card">
        @unless (auth()->user()->client->isIndividual())<p class="eyebrow">{{ auth()->user()->client->company }}</p>@endunless
        <h1 class="mt-1 text-2xl font-bold">{{ $firstVisit ? 'Welcome, '.auth()->user()->name.'.' : 'Portal guide' }}</h1>
        <p class="mt-2 text-muted">This is your {{ config('company.short') }} client portal: everything about your project in one place. Here is how it works.</p>

        <ol class="mt-6 grid gap-3 sm:grid-cols-2">
            @foreach ($steps as $i => [$icon, $heading, $text])
                <li class="flex gap-3 rounded-xl bg-paper p-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700"><x-icon :name="$icon" /></span>
                    <span><span class="block font-semibold"><span class="sr-only">Step {{ $i + 1 }}: </span>{{ $heading }}</span><span class="mt-1 block text-sm text-muted">{{ $text }}</span></span>
                </li>
            @endforeach
        </ol>
        <p class="mt-4 text-sm text-muted">We email you whenever something needs your attention, and the Overview page always shows what to do next. Payment options appear once there is something to pay.</p>

        @if ($firstVisit)
            <form method="post" action="{{ route('portal.welcome.complete') }}" class="mt-6 border-t border-line pt-6">
                @csrf
                @if (auth()->user()->usesIssuedPassword())
                <h2 class="font-bold">Choose your own password <span class="font-normal text-muted">(recommended)</span></h2>
                <p class="mt-1 text-sm text-muted">Replace the temporary password you were given with one only you know. Leave these blank to keep it for now.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-field label="New password" name="password" hint="At least 10 characters."><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" class="input" aria-describedby="password-hint"></x-field>
                    <x-field label="Confirm new password" name="password_confirmation"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="input"></x-field>
                </div>
                @endif
                <button class="btn btn-primary mt-6 w-full sm:w-auto">Get started</button>
            </form>
        @else
            <a href="{{ route('portal.dashboard') }}" class="btn btn-primary mt-6">Back to overview</a>
        @endif
    </div>
</x-layout>
