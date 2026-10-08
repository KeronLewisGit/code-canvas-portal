<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', 'Password updated. Sign in with the new one.')
            : back()->withErrors(['email' => __($status)]);
    }

    public function edit(): View
    {
        return view('auth.account');
    }

    public function change(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);
        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();

        return back()->with('saved', 'Password changed.');
    }
}
