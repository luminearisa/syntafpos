<?php

namespace App\Policies;

use App\Models\Category;

class CategoryPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'categories';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Category ? $model->company_id : null;
    }
}
