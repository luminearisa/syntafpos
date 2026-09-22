<?php

namespace App\Enums;

/**
 * Where a sales return has got to.
 *
 * A return is a counter act, not a document typed up later: the customer is
 * standing there with the goods, so the stock comes back in the same transaction
 * that writes the return. `Draft` is therefore only ever the shape the row has
 * *inside* that transaction — `SaleReturnService` creates, prices, posts stock and
 * completes before the transaction commits, so a failure rolls the whole thing
 * back rather than leaving a half-written return behind.
 *
 * `Cancelled` is reserved for reversing one, which takes stock back out through
 * the ledger rather than deleting the row; nothing in this subphase raises it yet,
 * and having the state named is what stops a later "delete the return" route from
 * being added by accident.
 */
enum SaleReturnStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether this return has put stock back into the shop and counts towards the
     * quantities a sale already has returned.
     */
    public function isPosted(): bool
    {
        return $this === self::Completed;
    }
}
