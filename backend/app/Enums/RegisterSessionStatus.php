<?php

namespace App\Enums;

/**
 * Where a register's session has got to.
 *
 * The work order's flow is Open Register → Active Shift → Transactions → Cash
 * In/Out → Close Register, and these are the states along it. Two decisions are
 * load-bearing rather than cosmetic:
 *
 * **A closed shift is not terminal.** The work order gives a manager a "reopen
 * if authorized" path, so `Closed` must be able to lead back to `Open`, and the
 * money engine has to be able to put a session back on the books. What keeps
 * that honest is not the status but the audit trail and the fact that reopening
 * re-stamps the session on any payment taken after it.
 *
 * **Waiting approval is a flag, not a state.** A shift whose drawer is short by
 * more than the threshold is still a shift that has been closed — the cashier
 * counted it, handed the money over and went home. Turning variance approval
 * into a status would mean a closed session could be neither closed nor open,
 * and every query asking "what did this till take today?" would have to remember
 * to include a third answer. So `CloseRegisterRequest` records the outcome on
 * the close itself and `awaiting_approval` says it still needs a supervisor.
 */
enum RegisterSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether the till may run business on this session: take sales, record a
     * tender, put money in or take it out.
     */
    public function takesActivity(): bool
    {
        return $this === self::Open;
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}
