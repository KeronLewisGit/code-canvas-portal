<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Preview as client": an admin sees the portal exactly as one of the client's logins does, read-only.
 * PreviewReadOnly blocks every change while previewing; exiting signs the admin straight back in.
 */
class ClientPreviewController extends Controller
{
    public const SESSION_KEY = 'preview_admin_id';

    public function start(Request $request, Client $client): RedirectResponse
    {
        $login = $client->users()->oldest()->first();
        if (! $client->active || ! $login) {
            return back()->withErrors(['preview' => 'Preview needs an active client with a portal login.']);
        }
        $adminId = $request->user()->id;
        Auth::login($login);
        $request->session()->put(self::SESSION_KEY, $adminId);

        return redirect()->route('portal.dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);
        $admin = $adminId ? User::where('role', 'admin')->find($adminId) : null;
        $clientId = $request->user()?->client_id;
        if (! $admin) {
            Auth::logout();

            return redirect()->route('login');
        }
        Auth::login($admin);

        return $clientId ? redirect()->route('admin.clients.show', $clientId) : redirect()->route('admin.dashboard');
    }

    public static function active(): bool
    {
        return request()->hasSession() && request()->session()->has(self::SESSION_KEY);
    }
}
