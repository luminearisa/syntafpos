<?php

namespace App\Policies;

use App\Models\PosCart;
use App\Models\User;

/**
 * Authorization for point-of-sale carts.
 *
 * The pos group does not follow the catalogue's CRUD quartet, so the verb
 * mapping is overridden rather than forcing permission names that do not fit a
 * till:
 *
 *   pos.view       open the till, search products, read a cart
 *   pos.transact   work a cart — add, edit, discount and clear lines
 *   pos.hold       park and recall carts, and delete a parked draft
 *
 * Editing is not split from selling: a cashier who may work a cart may change
 * its quantities and discounts. Hold is separate because a shop often wants the
 * parked-cart queue under a supervisor rather than whoever is on the till.
 *
 * On top of these, the service enforces that a working cart belongs to the
 * cashier using it — a permission never grants access to another person's
 * open transaction.
 */
class PosCartPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'pos';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return $model instanceof PosCart ? $model->company_id : null;
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
        return $this->can($user, 'transact', $this->context->companyId());
    }

    public function update(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'transact', $this->companyIdOf($model));
    }

    public function delete(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'hold', $this->companyIdOf($model));
    }

    /**
     * Park a cart and bring parked carts back.
     */
    public function hold(User $user, PosCart $cart): bool
    {
        return $this->canAccessCompany($user, $cart->company_id)
            && $this->can($user, 'hold', $cart->company_id);
    }
}
