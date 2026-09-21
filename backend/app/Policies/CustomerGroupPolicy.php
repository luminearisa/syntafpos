<?php

namespace App\Policies;

use App\Models\CustomerGroup;
use App\Models\User;

/**
 * Customer groups are a light auxiliary of customers, so they carry no
 * permission of their own: reading rides customers.view and every mutation
 * rides customers.update.
 */
class CustomerGroupPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'customers';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof CustomerGroup ? $model->company_id : null;
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
        return $this->can($user, 'update', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }
}
