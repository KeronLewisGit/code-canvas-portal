<?php

namespace App\Http\Controllers;

use App\Services\PortalAccess;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same message either way so the form does not reveal which emails exist.
        return back()->with('status', 'If that email has an account, a reset link is on its way.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function ($user, string $password) {
            $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
            PortalAccess::signOutEverywhere($user);
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', 'Password updated. Sign in with the new one.')
            : back()->withErrors(['email' => __($status)]);
    }

    /** The "Set your password" link from a welcome or sign-in email. */
    public function invite(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email'), 'invite' => true]);
    }

    public function acceptInvite(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);

        $status = Password::broker('invites')->reset($request->only('email', 'password', 'password_confirmation', 'token'), function ($user, string $password) {
            $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
            PortalAccess::signOutEverywhere($user);
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', 'Your password is set. Sign in with your email and new password.')
            : back()->withErrors(['email' => $status === Password::InvalidToken ? 'This link has expired or was already used. Use "Forgot password?" below to get a new one.' : __($status)]);
    }

    public function edit(): View
    {
        return view('auth.account');
    }

    public function change(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);
        $request->user()->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save(); // the User model hashes it
        $request->session()->regenerate();
        PortalAccess::signOutEverywhere($request->user(), keepSessionId: $request->session()->getId()); // every other device is signed out

        return back()->with('saved', 'Password changed.');
    }
}
