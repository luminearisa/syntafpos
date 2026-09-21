<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'roles';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Role ? $model->company_id : null;
    }

    public function view(User $user, mixed $model): bool
    {
        // Global system roles are readable by any user with the permission.
        if ($model->company_id === null) {
            return $this->can($user, 'view', null);
        }

        return $this->canAccessCompany($user, $model->company_id)
            && $this->can($user, 'view', $model->company_id);
    }

    public function update(User $user, mixed $model): bool
    {
        if ($model->is_system) {
            return false;
        }

        return parent::update($user, $model);
    }

    public function delete(User $user, mixed $model): bool
    {
        if ($model->is_system) {
            return false;
        }

        return parent::delete($user, $model);
    }
}
