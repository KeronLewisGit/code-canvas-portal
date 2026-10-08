<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Portal logins for a client. The password is shown once so you can pass it on. */
class ClientUserController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')],
            'password' => ['nullable', 'string', 'min:10', 'max:100'],
        ]);
        $password = $data['password'] ?: Str::password(14, symbols: false);

        $client->users()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => $password, 'role' => 'client']);

        return back()->with('saved', 'Portal login created.')->with('credentials', ['email' => $data['email'], 'password' => $password]);
    }

    public function update(Request $request, Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);
        $password = Str::password(14, symbols: false);
        $user->forceFill(['password' => $password])->save();

        return back()->with('saved', 'Password reset.')->with('credentials', ['email' => $user->email, 'password' => $password]);
    }

    public function destroy(Client $client, User $user): RedirectResponse
    {
        abort_unless($user->client_id === $client->id, 404);
        $user->delete();

        return back()->with('saved', 'Login removed.');
    }
}
