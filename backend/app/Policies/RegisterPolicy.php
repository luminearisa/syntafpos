<?php

namespace App\Policies;

use App\Models\Register;

class RegisterPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'registers';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Register ? $model->company_id : null;
    }
}
