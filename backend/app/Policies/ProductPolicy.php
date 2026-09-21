<?php

namespace App\Policies;

use App\Models\Product;

class ProductPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'products';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Product ? $model->company_id : null;
    }
}
