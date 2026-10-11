<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard: role:admin or role:client. Clients must be attached to a client record (inactive ones are signed out by EnsureActiveClient). */
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

        return $next($request);
    }
}
