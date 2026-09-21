<?php

namespace App\Policies;

use App\Models\Unit;

class UnitPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'units';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Unit ? $model->company_id : null;
    }
}
