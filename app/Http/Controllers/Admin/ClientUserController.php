<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Portal logins for a client. The password is shown once so you can pass it on, and optionally emailed to the client. */
class ClientUserController extends Controller
{
    public function store(Request $request, Client $client, PortalAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'password' => ['nullable', 'string', 'min:10', 'max:100'],
            'send_email' => ['nullable', 'boolean'],
        ]);
        $result = $access->createLogin($client, $data['name'], $data['email'], $data['password'] ?? null, $request->boolean('send_email'));

        return back()->with('saved', $result['emailed'] ? 'Portal login created. A link to set their password was emailed to '.$result['user']->email.'.' : 'Portal login created.')
            ->with('credentials', PortalAccess::banner($result));
    }

    public function update(Request $request, Client $client, User $user, PortalAccess $access): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);
        $result = $access->resetPassword($user, $request->boolean('send_email'));

        return back()->with('saved', $result['emailed'] ? 'A link to choose a new password was emailed to '.$user->email.'.' : 'Password reset. Pass the new one on to the client.')
            ->with('credentials', PortalAccess::banner($result));
    }

    public function destroy(Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);
        $user->delete();

        return back()->with('saved', 'Login removed.');
    }
}
