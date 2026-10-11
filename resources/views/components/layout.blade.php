@props(['title' => null, 'plain' => false, 'wide' => false])
@php($company = config('company'))
@php($user = auth()->user())
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ $company['short'] }} Portal</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#1863dc">
    <link rel="icon" href="{{ asset('images/brand/mark.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full">
<a href="#main" class="skip-link">Skip to main content</a>
@if ($plain || ! $user)
    <main id="main" class="flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full {{ $wide ? 'max-w-3xl' : 'max-w-md' }}">
            <a href="{{ route('home') }}" class="mb-8 flex justify-center" aria-label="{{ $company['name'] }} home"><x-logo :full="true" class="h-16 w-auto" /></a>
            {{ $slot }}
        </div>
    </main>
@else
    @php($admin = $user->isAdmin())
    {{-- Payment options appear only once the client has an issued contract or invoice to pay; past payments stay reachable. --}}
    @php($canPay = ! $admin && $user->client?->hasPayables())
    @php($showPayments = $admin || $canPay || $user->client?->payments()->exists())
    @php($nav = $admin
        ? [['admin.dashboard', 'Dashboard', 'admin.dashboard', 'home'], ['admin.clients.index', 'Clients', 'admin.clients.*', 'clients'], ['admin.contracts.index', 'Contracts', 'admin.contracts.*', 'contracts'], ['admin.quotations.index', 'Quotations', 'admin.quotations.*', 'quotations'], ['admin.invoices.index', 'Invoices', 'admin.invoices.*', 'invoices'], ['admin.payments.index', 'Payments', 'admin.payments.*', 'payments'], ['admin.reports.revenue', 'Reports', 'admin.reports.*', 'chart'], ['admin.settings.terms', 'Settings', 'admin.settings.*', 'settings']]
        : [['portal.dashboard', 'Overview', 'portal.dashboard', 'home'], ['portal.contracts.index', 'Contracts', 'portal.contracts.*', 'contracts'], ['portal.quotations.index', 'Quotations', 'portal.quotations.*', 'quotations'], ['portal.invoices.index', 'Invoices', 'portal.invoices.*', 'invoices'], ['portal.payments.index', 'Payments', 'portal.payments.*', 'payments'], ['portal.welcome', 'Portal guide', 'portal.welcome', 'help']])
    @php($nav = array_values(array_filter($nav, fn ($item) => $item[0] !== 'portal.payments.index' || $showPayments)))
    <div x-data="{ open: false, desktop: window.matchMedia('(min-width: 1024px)').matches }" x-init="window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => desktop = e.matches); $watch('open', (v) => v && $nextTick(() => $refs.sidebar.querySelector('a, button')?.focus()))" @keydown.escape.window="open = false" class="min-h-screen lg:flex">
        <aside id="sidebar" x-ref="sidebar" :inert="!open && !desktop" class="sidebar no-print fixed inset-y-0 left-0 z-40 flex h-screen w-72 -translate-x-full flex-col text-white transition lg:sticky lg:top-0 lg:translate-x-0" :class="open && 'translate-x-0'" aria-label="Main navigation">
            <div class="flex h-16 items-center justify-between px-5">
                <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-lg" aria-label="{{ $company['name'] }} home">
                    <x-logo class="size-9" />
                    <span class="leading-tight"><span class="block font-display text-sm font-semibold">{{ $company['short'] }}</span><span class="block text-xs text-white/70">{{ $admin ? 'Admin' : 'Client portal' }}</span></span>
                </a>
                <button type="button" class="btn btn-sm text-white/80 hover:bg-white/10 hover:text-white lg:hidden" @click="open = false" aria-label="Close menu"><x-icon name="close" /></button>
            </div>
            <nav class="mt-2 flex-1 space-y-1 overflow-y-auto px-3" aria-label="Pages">
                @foreach ($nav as [$route, $label, $pattern, $icon])
                    @php($current = request()->routeIs($pattern))
                    <a href="{{ route($route) }}" class="nav-link {{ $current ? 'active' : '' }}" @if ($current) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
                @endforeach
            </nav>
            <div class="border-t border-white/10 p-4 text-sm">
                <p class="truncate font-semibold">{{ $user->name }}</p>
                <p class="truncate text-xs text-white/70">{{ $admin ? $company['name'] : $user->client?->company }}</p>
                <div class="mt-4 flex items-center gap-1">
                    <a href="{{ route('account') }}" class="btn btn-sm text-white/80 hover:bg-white/10 hover:text-white"><x-icon name="key" class="size-4" />Password</a>
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-sm text-white/80 hover:bg-white/10 hover:text-white"><x-icon name="logout" class="size-4" />Sign out</button></form>
                </div>
            </div>
        </aside>
        <div x-show="open" x-cloak class="fixed inset-0 z-30 bg-ink/50 lg:hidden" @click="open = false" aria-hidden="true"></div>
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="no-print flex h-16 items-center justify-between border-b border-line bg-white px-4 lg:hidden">
                <button type="button" class="btn btn-light btn-sm" @click="open = true" aria-controls="sidebar" :aria-expanded="open.toString()"><x-icon name="menu" class="size-4" />Menu</button>
                <a href="{{ route('home') }}" class="flex items-center gap-2 text-sm font-semibold" aria-label="{{ $company['name'] }} home"><x-logo class="size-7" />{{ $company['short'] }}</a>
                <span class="w-20"></span>
            </header>
            <main id="main" class="flex-1 px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
                <div class="mx-auto max-w-6xl">
                    @if (session('saved'))<div role="status" class="no-print mb-5 flex items-start gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 ring-1 ring-emerald-200"><span aria-hidden="true">✓</span><span>{{ session('saved') }}</span></div>@endif
                    @if (session('warning'))<div role="alert" class="no-print mb-5 flex items-start gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 ring-1 ring-amber-200"><span aria-hidden="true">!</span><span>{{ session('warning') }}</span></div>@endif
                    @if ($user->usesIssuedPassword() && ! request()->routeIs('account'))
                        <div role="status" class="no-print mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-100"><span>You are still using the password we emailed you. Choose your own to keep your account secure.</span><a href="{{ route('account') }}" class="btn btn-light btn-sm">Choose a password</a></div>
                    @endif
                    @if (session('credentials'))
                        <div role="status" class="no-print mb-5 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                            <p class="font-bold">{{ ! empty(session('credentials')['emailed']) ? 'These details were emailed to the client. They are shown here only this once in case you also want to pass them on.' : 'Pass these details to the client. The password is shown only this once.' }}</p>
                            <p class="mt-1 font-mono">Email: {{ session('credentials')['email'] }}</p>
                            <p class="font-mono">Password: {{ session('credentials')['password'] }}</p>
                            <p class="mt-1 text-xs">Sign in at {{ route('login') }}</p>
                        </div>
                    @endif
                    @if ($errors->any())<div role="alert" class="no-print mb-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-900 ring-1 ring-red-200"><p class="font-bold">Please check the form.</p><ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
                    {{ $slot }}
                </div>
            </main>
            @if (! $admin)
                <footer class="no-print px-4 py-6 text-center text-sm text-muted sm:px-6 lg:px-10">Need a hand? Email <a href="mailto:{{ $company['email'] }}" class="font-semibold text-brand-700 underline-offset-2 hover:underline">{{ $company['email'] }}</a> or call {{ $company['phone'] }}.</footer>
            @endif
        </div>
    </div>
@endif
</body>
</html>
