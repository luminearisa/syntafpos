<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'users';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $this->context->companyId();
    }

    public function view(User $user, mixed $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $this->can($user, 'view', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $this->can($user, 'update', $this->context->companyId());
    }
}
