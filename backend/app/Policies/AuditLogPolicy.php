<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'audit';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof AuditLog ? $model->company_id : null;
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
}
