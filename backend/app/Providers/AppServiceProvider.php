<?php

namespace App\Providers;

use App\Support\BusinessContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BusinessContext::class);
    }

    public function boot(): void
    {
        // Laravel 13 resolves `throttle:api` per authenticated model, so the
        // limiter is registered here rather than relying on the framework default.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
