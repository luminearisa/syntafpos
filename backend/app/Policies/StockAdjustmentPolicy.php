<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;

/**
 * Stock adjustments are gated by the inventory permission family.
 *
 * The catalogue exposes inventory.view / inventory.adjust / inventory.approve
 * rather than a generic CRUD triple, so the standard create/update/delete
 * checks are remapped onto those business verbs.
 */
class StockAdjustmentPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'inventory';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof StockAdjustment ? $model->company_id : null;
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
        return $this->can($user, 'adjust', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'adjust', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'adjust', $this->companyIdOf($model));
    }

    /**
     * The submit/approve/post transitions carry their own permission.
     */
    public function submit(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'adjust', $this->companyIdOf($model));
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
