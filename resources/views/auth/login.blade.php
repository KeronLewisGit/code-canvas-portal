<x-layout title="Sign in" :plain="true">
    <div class="card">
        <h1 class="text-xl font-bold">Sign in</h1>
        <p class="mt-1 text-sm text-muted">Contracts, invoices and payments in one place.</p>
        @if (session('status'))<p class="mt-4 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>@endif
        <form method="post" action="{{ route('login.attempt') }}" class="mt-6 space-y-4">
            @csrf
            <x-field label="Email" name="email" required><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="input"></x-field>
            <x-field label="Password" name="password" required><input id="password" name="password" type="password" required autocomplete="current-password" class="input"></x-field>
            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" name="remember" value="1" class="size-4 rounded border-line"> Keep me signed in</label>
                <a href="{{ route('password.request') }}" class="font-semibold text-brand-700 hover:underline">Forgot password?</a>
            </div>
            <button class="btn btn-primary w-full">Sign in</button>
        </form>
    </div>
</x-layout>
