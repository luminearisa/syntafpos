<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'companies';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Company ? $model->id : null;
    }

    public function view(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $model->id)
            && $this->can($user, 'view', $model->id);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create', null);
    }
}
