<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Purchase orders are gated by the purchases permission family.
 *
 * Submission and sending are order edits; approval and closure carry the
 * approve permission; cancellation carries its own permission.
 */
class PurchaseOrderPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'purchases';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof PurchaseOrder ? $model->company_id : null;
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
     * Submitting an order for approval is an edit of the order.
     */
    public function submit(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    /**
     * Approval is the financial gate on the order.
     */
    public function approve(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }

    /**
     * Sending an approved order to the supplier is an order edit.
     */
    public function send(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'update', $this->companyIdOf($model));
    }

    /**
     * Closing is a terminal manual action and rides the approve permission.
     */
    public function close(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }

    /**
     * Cancellation carries its own permission.
     */
    public function cancel(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'cancel', $this->companyIdOf($model));
    }
}
