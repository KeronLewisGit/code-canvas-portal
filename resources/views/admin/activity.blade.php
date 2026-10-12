<x-layout title="Client activity">
    <x-page title="Client activity" subtitle="Who has signed in to the client portal, when, and the last thing each login did. Your own previews are never counted." />
    <form class="mb-6 flex flex-wrap gap-2" aria-label="Filters" data-autosubmit>
        <input name="q" type="search" aria-label="Search logins" value="{{ request('q') }}" placeholder="Search name, email or client" class="input max-w-sm">
        <select name="show" aria-label="Show" class="input max-w-56"><option value="">All logins</option><option value="never" @selected(request('show') === 'never')>Never signed in ({{ $neverCount }})</option></select>
        <button class="sr-only focus:not-sr-only btn btn-light">Search</button>
    </form>

    <form method="get" action="{{ route('admin.activity.email') }}" x-data="{ picked: 0 }" @change="picked = $el.querySelectorAll('input[name=\'users[]\']:checked').length">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <button class="btn btn-primary btn-sm" :disabled="picked === 0"><x-icon name="mail" class="size-4" /> <span x-text="picked ? 'Email ' + picked + ' selected' : 'Email selected'">Email selected</span></button>
            @if ($neverCount)<a href="{{ route('admin.activity.email', ['never' => 1]) }}" class="btn btn-light btn-sm">Email everyone who hasn't signed in ({{ $neverCount }})</a>@endif
        </div>
        <div class="card overflow-x-auto p-0 sm:p-0">
            <table class="table min-w-[820px]"><thead><tr>
                <th class="w-10"><input type="checkbox" aria-label="Select all on this page" class="size-4 rounded border-line" @change="$root.querySelectorAll('input[name=\'users[]\']').forEach(c => c.checked = $event.target.checked)"></th>
                <th>Login</th><th>Client</th><th>Last signed in</th><th>Last activity</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
                @forelse ($logins as $u)
                    <tr>
                        <td><input type="checkbox" name="users[]" value="{{ $u->id }}" aria-label="Select {{ $u->name }}" class="size-4 rounded border-line"></td>
                        <td><span class="font-semibold">{{ $u->name }}</span><span class="block text-xs text-muted">{{ $u->email }}</span></td>
                        <td>@if ($u->client)<a href="{{ route('admin.clients.show', $u->client) }}" class="hover:text-brand-700">{{ $u->client->company }}</a>@if (! $u->client->active)<x-badge status="cancelled" class="ml-1">Inactive</x-badge>@endif @endif</td>
                        <td class="whitespace-nowrap text-sm">@if ($u->last_login_at){{ $u->last_login_at->diffForHumans() }}<span class="block text-xs text-muted">{{ $u->last_login_at->format('j M Y, g:ia') }}</span>@else<span class="text-muted">Never</span>@endif</td>
                        <td class="text-sm">@if ($u->last_seen_at){{ $u->last_activity }}<span class="block text-xs text-muted">{{ $u->last_seen_at->diffForHumans() }} · {{ $u->last_seen_at->format('j M Y, g:ia') }}</span>@else<span class="text-muted">No activity yet</span>@endif</td>
                        <td class="text-right">@if ($u->client?->active)<a href="{{ route('admin.activity.email', ['users' => [$u->id]]) }}" class="btn btn-light btn-sm">Email</a>@endif</td>
                    </tr>
                @empty<tr><td colspan="6"><x-empty>No client logins match.</x-empty></td></tr>@endforelse
            </tbody></table>
        </div>
    </form>
    <div class="mt-4">{{ $logins->links() }}</div>

    <section class="card mt-6 overflow-x-auto">
        <x-card-header title="Recent sign-ins" />
        <table class="table mt-4 min-w-[640px]"><thead><tr><th>When</th><th>Who</th><th>Device</th><th>IP address</th></tr></thead><tbody>
            @forelse ($signIns as $s)
                @php($at = \Illuminate\Support\Carbon::parse($s->created_at))
                <tr>
                    <td class="whitespace-nowrap text-sm">{{ $at->format('j M Y, g:ia') }}<span class="block text-xs text-muted">{{ $at->diffForHumans() }}</span></td>
                    <td><span class="font-semibold">{{ $s->name }}</span><span class="block text-xs text-muted">{{ $s->role === 'admin' ? 'Admin' : $s->company }}</span></td>
                    <td class="text-sm">{{ \App\Http\Controllers\Admin\ActivityController::device($s->user_agent) }}</td>
                    <td class="font-mono text-xs">{{ $s->ip }}</td>
                </tr>
            @empty<tr><td colspan="4" class="text-muted">No sign-ins recorded yet.</td></tr>@endforelse
        </tbody></table>
    </section>
</x-layout>
