<?php

namespace App\Policies;

use App\Models\CashMovement;
use App\Models\RegisterSession;
use App\Models\User;

/**
 * Authorization for the drawer's lifecycle.
 *
 * Six verbs, mapped onto the two groups the work order describes rather than onto
 * the catalogue's CRUD quartet, because a shift is a custody chain and not a
 * record someone is editing:
 *
 *   register_sessions.view     read shifts, movements and the closing report
 *   register_sessions.open     start a shift with float (cashier)
 *   register_sessions.process  record cash in / cash out on an open shift (cashier)
 *   register_sessions.close    count the drawer and shut the shift (cashier)
 *   register_sessions.approve  sign off a variance over threshold (manager)
 *   register_sessions.reopen   put a closed shift back open (manager)
 *
 * The split that matters is approve/reopen against the other four: a cashier may
 * legitimately do everything a shift involves except rule on their own shortage.
 * Holding the close permission and the approve permission at once is how a
 * variance stops being a control, which is why the seeded cashier role has the
 * first four only.
 *
 * There is no update or delete: a shift is a counted record of money, corrected by
 * reopening it and counting again, never by editing the figures.
 */
class RegisterSessionPolicy extends BusinessEntityPolicy
{
    protected function module(): string
    {
        return 'register_sessions';
    }

    protected function companyIdOf(mixed $model): ?int
    {
        return match (true) {
            $model instanceof RegisterSession => $model->company_id,
            $model instanceof CashMovement => $model->company_id,
            default => null,
        };
    }

    public function open(User $user): bool
    {
        return $this->can($user, 'open', $this->context->companyId());
    }

    /**
     * Cash in and out on a shift this user may see.
     */
    public function process(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'process', $this->companyIdOf($model));
    }

    public function close(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'close', $this->companyIdOf($model));
    }

    public function approve(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'approve', $this->companyIdOf($model));
    }

    public function reopen(User $user, mixed $model): bool
    {
        return $this->canAccessCompany($user, $this->companyIdOf($model))
            && $this->can($user, 'reopen', $this->companyIdOf($model));
    }

    /**
     * Money that has been counted is not editable, and a shift cannot be deleted
     * out of existence: it is reopened and counted again, or it stands.
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
