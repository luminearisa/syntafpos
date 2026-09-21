<?php

namespace App\Policies;

use App\Models\Supplier;

class SupplierPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'suppliers';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Supplier ? $model->company_id : null;
    }
}
