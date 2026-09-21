<?php

namespace App\Policies;

use App\Models\StockOpname;
use App\Models\User;

/**
 * Stock opnames are gated by the inventory permission family.
 *
 * Counting uses inventory.opname; approval and posting use inventory.approve.
 */
class StockOpnamePolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'inventory';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof StockOpname ? $model->company_id : null;
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
        return $this->can($user, 'opname', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'opname', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'opname', $this->companyIdOf($model));
    }

    /**
     * The approve transition and the final posting carry their own permission.
     */
    public function startCounting(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'opname', $this->companyIdOf($model));
    }

    public function sendToReview(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'opname', $this->companyIdOf($model));
    }

    public function approve(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }

    /**
     * Posting writes the immutable ledger, so it rides the approve permission.
     */
    public function post(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }
}
