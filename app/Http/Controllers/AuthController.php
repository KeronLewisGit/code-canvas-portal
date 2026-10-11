<?php

namespace App\Http\Controllers;

use App\Services\ActivityTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Those details do not match our records.'])->onlyInput('email');
        }

        $user = Auth::user();
        if ($user->isClient() && ! $user->client?->active) {
            Auth::logout();

            return back()->withErrors(['email' => 'This account is no longer active. Contact '.config('company.email').' for help.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        app(ActivityTracker::class)->signedIn($user, $request);

        // First sign-in: show the portal guide; any link they followed (e.g. from an email) is kept for afterwards.
        return $user->needsWelcome() ? redirect()->route('portal.welcome') : redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
