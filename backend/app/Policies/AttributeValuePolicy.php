<?php

namespace App\Policies;

use App\Models\AttributeValue;

class AttributeValuePolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        // Values ride the attribute permission set: there is no separate
        // catalogue entry for them.
        return 'attributes';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof AttributeValue ? $model->attribute?->company_id : null;
    }
}
