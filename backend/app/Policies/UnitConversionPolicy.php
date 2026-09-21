<?php

namespace App\Policies;

use App\Models\UnitConversion;

class UnitConversionPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        // Conversions ride the unit permission set: there is no separate
        // catalogue entry for them.
        return 'units';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof UnitConversion ? $model->company_id : null;
    }
}
