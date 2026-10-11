<?php

namespace App\Providers;

use App\Services\PendingActions;
use App\Support\LedgerCache;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PendingActions::class); // memo lives for one request
    }

    public function boot(): void
    {
        // Payment totals are memoised per request only (see LedgerCache).
        $this->app['events']->listen(RouteMatched::class, fn () => LedgerCache::flush());

        // Policies live in App\Policies\{Model}Policy and are discovered by convention.

        // Sign-in attempts are limited per address as well as per IP, so guessing one account's password
        // from many IPs is slowed down too.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
        ]);
    }
}
