<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;

/**
 * Purchase requests are gated by the purchases permission family.
 *
 * Raising and editing use purchases.create / purchases.update; approval and
 * rejection use purchases.approve; converting into a purchase order creates a
 * new document, so it uses purchases.create.
 */
class PurchaseRequestPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'purchases';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof PurchaseRequest ? $model->company_id : null;
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
        return $this->can($user, 'create', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'delete', $this->companyIdOf($model));
    }

    /**
     * Submitting a request for approval is an edit of the request itself.
     */
    public function submit(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    /**
     * Approving and rejecting a request carry the approve permission.
     */
    public function approve(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }

    public function reject(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }

    /**
     * Conversion spawns a purchase order, so it needs create rights.
     */
    public function convert(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'create', $this->companyIdOf($model));
    }
}
