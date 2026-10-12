<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifier;
use App\Services\PortalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Email client logins from the Client activity page: a message you write, or their welcome link again. */
class ClientEmailController extends Controller
{
    private const MAX = 200;

    public function create(Request $request): View|RedirectResponse
    {
        $recipients = $this->recipients($request);
        if ($recipients->isEmpty()) {
            return redirect()->route('admin.activity')->with('warning', 'Tick at least one login to email.');
        }

        return view('admin.activity-email', [
            'recipients' => $recipients,
            'kind' => old('kind', $request->query('kind', $recipients->every(fn (User $u) => $u->last_login_at === null) ? 'welcome' : 'message')),
        ]);
    }

    public function store(Request $request, Notifier $notifier, PortalAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'users' => ['required', 'array', 'max:'.self::MAX], 'users.*' => ['integer'],
            'kind' => ['required', 'in:message,welcome'],
            'subject' => ['exclude_unless:kind,message', 'required', 'string', 'max:150'],
            'body' => ['exclude_unless:kind,message', 'required', 'string', 'max:10000'],
            'button' => ['boolean'],
        ]);
        $recipients = $this->recipients($request);

        $sent = 0;
        $failed = [];
        foreach ($recipients->values() as $i => $user) {
            if ($i > 0) {
                usleep(300_000); // go easy on the mail server when sending to many
            }
            $ok = $data['kind'] === 'welcome'
                ? $access->sendMigrationWelcome($user)
                : $notifier->message($user, $data['subject'], $data['body'], $request->boolean('button'));
            $ok ? $sent++ : $failed[] = $user->email;
        }

        $redirect = redirect()->route('admin.activity')->with('saved', 'Sent '.$sent.' email'.($sent === 1 ? '' : 's').'.');

        return $failed === [] ? $redirect : $redirect->with('warning', 'Could not send to '.implode(', ', $failed).'. Check the MAIL settings and try again.');
    }

    /** Ticked logins (users[]), or everyone who has never signed in (never=1). Only logins of active clients. */
    private function recipients(Request $request): Collection
    {
        $ids = array_filter(array_map('intval', (array) $request->input('users', [])));

        return User::with('client')->where('role', 'client')
            ->whereHas('client', fn (Builder $c) => $c->where('active', true))
            ->when($request->boolean('never'), fn ($q) => $q->whereNull('last_login_at'), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')->limit(self::MAX)->get();
    }
}
