<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentProviderRegistry;
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

        // Subphase 3.3 ships no gateway, so the registry is deliberately empty:
        // a payment method that names a provider is refused rather than silently
        // marked paid. 3.8 tags the Midtrans implementation onto this same
        // singleton, and nothing in PaymentService or the controllers changes.
        $this->app->singleton(PaymentProviderRegistry::class, fn () => new PaymentProviderRegistry);
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
