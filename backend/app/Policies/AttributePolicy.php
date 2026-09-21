<?php

namespace App\Policies;

use App\Models\Attribute;

class AttributePolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'attributes';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Attribute ? $model->company_id : null;
    }
}
