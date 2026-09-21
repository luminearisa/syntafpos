<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;

/**
 * Goods receipts ride the purchases permission family.
 *
 * Every step of receiving a document, from raising the draft to posting the
 * ledger, uses purchases.receive; reading uses purchases.view.
 */
class GoodsReceiptPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'purchases';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof GoodsReceipt ? $model->company_id : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'view');
    }

    public function view(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'view', $this->companyIdOf($model));
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'receive', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'receive', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'receive', $this->companyIdOf($model));
    }

    /**
     * Posting writes the immutable ledger, so it carries its own permission.
     */
    public function post(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'receive', $this->companyIdOf($model));
    }
}
