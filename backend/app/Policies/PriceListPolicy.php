<?php

namespace App\Policies;

use App\Models\PriceList;

class PriceListPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'price_lists';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof PriceList ? $model->company_id : null;
    }
}
