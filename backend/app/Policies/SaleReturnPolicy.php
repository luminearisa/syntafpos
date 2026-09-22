<?php

namespace App\Policies;

use App\Models\SaleReturn;
use App\Models\User;

/**
 * Authorization for sales returns.
 *
 * A return is judged by the `sales` family's permissions rather than carrying its
 * own: reading one is reading a sale's history, and raising one is the
 * `sales.return` authority, deliberately split off from `sales.cancel` because
 * bringing goods back against a document and withdrawing an open ticket are
 * different acts with different evidence.
 *
 * There is no update and no delete. A return is corrected by posting a second
 * return, not by editing the quantities on the first — which is what keeps the
 * stock ledger and the slips telling one story.
 */
class SaleReturnPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'sales';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof SaleReturn ? $model->company_id : null;
    }

    /**
     * Raising a return needs `sales.return`, not `sales.create` — a shop that
     * wants anyone to sell often wants a supervisor to be the one who takes goods
     * back.
     */
    public function create(User $user): bool
    {
        return $this->can($user, 'return', $this->context->companyId());
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
