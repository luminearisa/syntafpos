<?php

namespace App\Policies;

use App\Models\Warehouse;

class WarehousePolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'warehouses';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Warehouse ? $model->company_id : null;
    }
}
