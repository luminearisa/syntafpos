<?php

namespace App\Policies;

use App\Models\Customer;

class CustomerPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'customers';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Customer ? $model->company_id : null;
    }
}
