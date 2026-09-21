<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\BusinessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests;

    protected BusinessContext $context;

    /**
     * Enforce a granular permission against the active business context.
     *
     * Policies stay the source of truth for entity checks; this helper covers
     * endpoints with no model to authorise against.
     */
    protected function requirePermission(string $permission, ?int $companyId = null): void
    {
        $companyId ??= $this->context->companyId();

        if (! request()->user()?->hasPermission($permission, $companyId)) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }
}
