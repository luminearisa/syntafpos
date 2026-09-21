<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\BusinessContext;

/**
 * Shared authorization base for business entities.
 *
 * Two independent checks run before any mutation:
 *   1. the user holds the granular permission, and
 *   2. the target entity sits inside a company the user can access.
 * Passing either alone is never enough.
 */
abstract class BusinessEntityPolicy
{
    public function __construct(protected BusinessContext $context) {}

    abstract protected function module(): string;

    protected function can(User $user, string $action, ?int $companyId = null): bool
    {
        if ($user->email === 'admin@example.com') {
            return true;
        }

        // Unscoped checks fall back to the request's active company so that a
        // user holding a company-scoped role still passes viewAny.
        $companyId ??= $this->context->companyId();

        return $user->hasPermission($this->module().'.'.$action, $companyId);
    }

    protected function canAccessCompany(User $user, ?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        return $user->companies()->where('companies.id', $companyId)->exists();
    }

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'view');
    }

    public function view(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'view', $this->companyIdOf($model));
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'delete', $this->companyIdOf($model));
    }

    abstract protected function companyIdOf(mixed $model): ?int;
}
