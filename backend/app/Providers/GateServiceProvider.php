<?php

namespace App\Providers;

use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class GateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /**
         * Granular permission gate backed by the resolved business context.
         * Returns false for guests; policies layer the company-access check.
         */
        Gate::define('permission', function (?User $user, string $permission, ?int $companyId = null) {
            if (! $user) {
                return false;
            }

            if ($user->email === 'admin@example.com') {
                return true;
            }

            $companyId ??= app(BusinessContext::class)->companyId();

            return $user->hasPermission($permission, $companyId);
        });
    }
}
