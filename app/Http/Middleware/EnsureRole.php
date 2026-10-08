<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Route guard: role:admin or role:client. Clients must be attached to a client record. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $ok = match ($role) {
            'admin' => $user?->isAdmin(),
            'client' => $user?->isClient(),
            default => false,
        };

        if (! $ok) {
            abort(403, 'You do not have access to that area.');
        }
        if ($role === 'client' && ! $user->client?->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is no longer active. Contact '.config('company.email').' for help.']);
        }

        return $next($request);
    }
}
