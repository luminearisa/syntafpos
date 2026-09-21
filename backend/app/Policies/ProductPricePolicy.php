<?php

namespace App\Policies;

use App\Models\ProductPrice;
use App\Models\User;

/**
 * Price lines are children of a product: listing reads products.view and every
 * mutation is gated on products.update.
 */
class ProductPricePolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'products';
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'update', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    protected function companyIdOf(mixed $model): ?int
    {
        // A price line carries no company column; its price list is the scope.
        return $model instanceof ProductPrice ? $model->priceList?->company_id : null;
    }
}
