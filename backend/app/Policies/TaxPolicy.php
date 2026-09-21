<?php

namespace App\Policies;

use App\Models\Tax;

class TaxPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'taxes';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Tax ? $model->company_id : null;
    }
}
