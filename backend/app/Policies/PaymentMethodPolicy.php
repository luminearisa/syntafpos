<?php

namespace App\Policies;

use App\Models\PaymentMethod;

class PaymentMethodPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'payment_methods';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof PaymentMethod ? $model->company_id : null;
    }
}
