<?php

namespace App\Policies;

use App\Models\ProductBarcode;
use App\Models\User;

/**
 * Barcodes are children of a product: listing reads products.view and every
 * mutation is gated on products.update.
 */
class ProductBarcodePolicy extends BusinessEntityPolicy
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
        return $model instanceof ProductBarcode ? $model->company_id : null;
    }
}
