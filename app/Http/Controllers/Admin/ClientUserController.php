<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Portal logins for a client. The password is shown once so you can pass it on, and optionally emailed to the client. */
class ClientUserController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'password' => ['nullable', 'string', 'min:10', 'max:100'],
            'send_email' => ['nullable', 'boolean'],
        ]);
        $password = ($data['password'] ?? null) ?: Str::password(14, symbols: false);

        $user = $client->users()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => $password, 'role' => 'client']);
        $emailed = $request->boolean('send_email') && app(Notifier::class)->loginDetails($user, $password, isNew: true);

        return back()->with('saved', $emailed ? 'Portal login created and emailed to '.$user->email.'.' : 'Portal login created.')
            ->with('credentials', ['email' => $user->email, 'password' => $password, 'emailed' => $emailed]);
    }

    public function update(Request $request, Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);
        $password = Str::password(14, symbols: false);
        $user->forceFill(['password' => $password])->save();
        $emailed = $request->boolean('send_email') && app(Notifier::class)->loginDetails($user, $password, isNew: false);

        return back()->with('saved', $emailed ? 'Password reset and emailed to '.$user->email.'.' : 'Password reset.')
            ->with('credentials', ['email' => $user->email, 'password' => $password, 'emailed' => $emailed]);
    }

    public function destroy(Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);
        $user->delete();

        return back()->with('saved', 'Login removed.');
    }
}
