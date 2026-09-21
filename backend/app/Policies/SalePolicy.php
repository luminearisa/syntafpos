<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

/**
 * Authorization for sales.
 *
 * Four verbs, because a transactional record does not map onto the catalogue's
 * CRUD quartet:
 *
 *   sales.view      read a sale, its invoice and its receipt
 *   sales.create    check a cart out — the act that creates the document
 *   sales.complete  settle or close a ticket that is not yet completed
 *   sales.cancel    withdraw a ticket, reversing the stock it posted
 *
 * There is no update or delete: a posted transaction is corrected by a
 * cancellation, never by editing the row, which is what keeps an invoice and the
 * stock ledger telling the same story.
 *
 * Cancel is its own permission rather than a rider on create, because the shop
 * that wants anyone on a till to sell usually wants a supervisor to be the only
 * one who can take goods back.
 */
class SalePolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'sales';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof Sale ? $model->company_id : null;
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create', $this->context->companyId());
    }

    /**
     * Settling a ticket a colleague raised is a different act from ringing one
     * up, so it is not folded into create.
     */
    public function complete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'complete', $this->companyIdOf($model));
    }

    public function cancel(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'cancel', $this->companyIdOf($model));
    }

    /**
     * A sale cannot be edited or removed after it exists; the status machine is
     * the only way forward.
     */
    public function update(User $user, mixed $model): bool
    {
        return false;
    }

    public function delete(User $user, mixed $model): bool
    {
        return false;
    }
}
