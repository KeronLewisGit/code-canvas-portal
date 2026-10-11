<x-layout title="Your account">
    <x-page title="Your account" eyebrow="Settings" />
    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card">
            <h2 class="text-lg font-bold">Change password</h2>
            <form method="post" action="{{ route('account.password') }}" class="mt-4 space-y-4">
                @csrf @method('patch')
                <x-field label="Current password" name="current_password" required><input id="current_password" name="current_password" type="password" autocomplete="current-password" required class="input"></x-field>
                <x-field label="New password" name="password" required hint="At least 10 characters."><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required class="input"></x-field>
                <x-field label="Confirm new password" name="password_confirmation" required><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input"></x-field>
                <button class="btn btn-primary">Update password</button>
            </form>
        </div>
        <div class="card text-sm">
            <h2 class="text-lg font-bold">Signed in as</h2>
            <p class="mt-4 font-semibold">{{ auth()->user()->name }}</p>
            <p class="text-muted">{{ auth()->user()->email }}</p>
            @if (auth()->user()->client && ! auth()->user()->client->isIndividual())<p class="mt-4 text-muted">Company: <span class="font-semibold text-ink">{{ auth()->user()->client->company }}</span></p><p class="mt-2 text-xs text-muted">To change company details, contact {{ config('company.email') }}.</p>@endif
        </div>
    </div>
</x-layout>
