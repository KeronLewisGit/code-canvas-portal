<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/** The first-sign-in guide. It stays available afterwards as "Portal guide" in the menu. */
class WelcomeController extends Controller
{
    public function show(Request $request): View
    {
        return view('portal.welcome', ['firstVisit' => $request->user()->needsWelcome()]);
    }

    /** Finishing the guide; on the first visit the client may also replace the password we emailed them. */
    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();
        $setPassword = $user->needsWelcome() && $request->filled('password');
        if ($setPassword) {
            $user->password = $request->validate(['password' => ['required', 'confirmed', PasswordRule::min(10)]])['password'];
            $user->password_changed_at = now();
        }
        $user->welcomed_at ??= now();
        $user->save();
        if ($setPassword) {
            $request->session()->regenerate();
        }

        return redirect()->intended(route('portal.dashboard'))->with('saved', $setPassword ? 'Password saved. You are all set.' : 'You are all set. Welcome aboard.');
    }
}
