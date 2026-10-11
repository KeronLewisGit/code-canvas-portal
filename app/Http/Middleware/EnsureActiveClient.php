<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated client is signed out on their next request anywhere in the app, including documents, files and
 * the account page, not only inside the portal area.
 */
class EnsureActiveClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->isClient() && ! $user->client?->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is no longer active. Contact '.config('company.email').' for help.']);
        }

        return $next($request);
    }
}
