<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarehouseTransfer;

/**
 * Warehouse transfers are gated by the inventory permission family.
 *
 * Raising and editing a transfer uses inventory.transfer; approval uses
 * inventory.approve.
 */
class WarehouseTransferPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'inventory';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof WarehouseTransfer ? $model->company_id : null;
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
        return $this->can($user, 'transfer', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transfer', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transfer', $this->companyIdOf($model));
    }

    /**
     * Moving goods around is the transfer permission; only approval carries the
     * separate approve permission.
     */
    public function submit(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transfer', $this->companyIdOf($model));
    }

    public function ship(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transfer', $this->companyIdOf($model));
    }

    public function receive(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transfer', $this->companyIdOf($model));
    }

    public function complete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transfer', $this->companyIdOf($model));
    }

    public function cancel(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transfer', $this->companyIdOf($model));
    }

    public function approve(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }
}
