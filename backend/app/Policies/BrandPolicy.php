<?php

namespace App\Policies;

use App\Models\Brand;

class BrandPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'brands';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Brand ? $model->company_id : null;
    }
}
