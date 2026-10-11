<x-layout title="Choose a new password" :plain="true">
    <div class="card">
        <h1 class="text-xl font-bold">Choose a new password</h1>
        <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-field label="Email" name="email" required><input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email" class="input"></x-field>
            <x-field label="New password" name="password" required hint="At least 10 characters."><input id="password" name="password" type="password" required autocomplete="new-password" class="input"></x-field>
            <x-field label="Confirm password" name="password_confirmation" required><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input"></x-field>
            <button class="btn btn-primary w-full">Save password</button>
        </form>
    </div>
</x-layout>
