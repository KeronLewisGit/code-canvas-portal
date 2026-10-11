@php($invite = $invite ?? false)
<x-layout :title="$invite ? 'Set your password' : 'Choose a new password'" :plain="true">
    <div class="card">
        <h1 class="text-xl font-bold">{{ $invite ? 'Set your password' : 'Choose a new password' }}</h1>
        @if ($invite)<p class="mt-1 text-sm text-muted">Choose the password you will use to sign in to your {{ config('company.short') }} client portal.</p>@endif
        <form method="post" action="{{ $invite ? route('password.invite.update') : route('password.update') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-field label="Email" name="email" required><input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email" class="input"></x-field>
            <x-field label="New password" name="password" required hint="At least 10 characters."><input id="password" name="password" type="password" required autocomplete="new-password" class="input"></x-field>
            <x-field label="Confirm password" name="password_confirmation" required><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input"></x-field>
            <button class="btn btn-primary w-full">{{ $invite ? 'Set password' : 'Save password' }}</button>
            @if ($invite)<p class="text-center text-sm text-muted">Link expired? <a href="{{ route('password.request') }}" class="font-semibold text-brand-700 hover:underline">Get a new one</a></p>@endif
        </form>
    </div>
</x-layout>
