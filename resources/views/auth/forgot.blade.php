<x-layout title="Reset password" :plain="true">
    <div class="card">
        <h1 class="text-xl font-bold">Reset your password</h1>
        <p class="mt-1 text-sm text-muted">Enter your email and we will send a reset link.</p>
        @if (session('status'))<p class="mt-4 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>@endif
        <form method="post" action="{{ route('password.email') }}" class="mt-6 space-y-4">
            @csrf
            <x-field label="Email" name="email" required><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="input"></x-field>
            <button class="btn btn-primary w-full">Send reset link</button>
            <a href="{{ route('login') }}" class="block text-center text-sm font-semibold text-muted hover:text-ink">Back to sign in</a>
        </form>
    </div>
</x-layout>
