<?php

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

/**
 * Authorization for refunds.
 *
 * The catalogue's CRUD quartet does not fit a document that hands money out, so
 * the verbs are the ones the work order names:
 *
 *   refunds.view     read a refund and its allocations
 *   refunds.create   raise one against a sale
 *   refunds.approve  sign off one the threshold referred to a person
 *   refunds.process  pay one out — the act that writes the tenders down
 *
 * Approve sits apart from process on purpose. A shop that lets a cashier hand
 * money back over the counter must still be able to insist that an exception
 * above the threshold is signed for by someone else, and a permission a person
 * holds for their own refund cannot gate that conversation.
 *
 * No update and no delete: a refund that went wrong is failed and re-raised, and
 * the tenders it wrote down are corrected by a second refund, never by editing.
 */
class RefundPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'refunds';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Refund ? $model->company_id : null;
    }

    public function approve(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }

    /**
     * Rejecting is the other half of approving an exception, so it shares the
     * permission: whoever may say yes must be able to say no.
     */
    public function reject(User $user, mixed $model): bool
    {
        return $this->approve($user, $model);
    }

    public function process(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'process', $this->companyIdOf($model));
    }

    /**
     * Completing, failing and processing are all "pay this out" and share the
     * `refunds.process` authority.
     */
    public function complete(User $user, mixed $model): bool
    {
        return $this->process($user, $model);
    }

    public function fail(User $user, mixed $model): bool
    {
        return $this->process($user, $model);
    }

    public function update(User $user, mixed $model): bool
    {
        return false;
    }

    public function delete(User $user, mixed $model): bool
    {
        return false;
    }
}
