<?php

namespace App\Http\Middleware;

use App\Services\ActivityTracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Notes a client's latest activity after a successful request (not when a form came back with errors). */
class TrackClientActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $failed = $response->getStatusCode() >= 400 || ($request->hasSession() && $request->session()->has('errors') && ! $request->isMethodSafe());
        if (! $failed) {
            rescue(fn () => app(ActivityTracker::class)->record($request), report: true); // never break a page over tracking
        }

        return $response;
    }
}
