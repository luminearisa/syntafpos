<?php

namespace App\Support;

/**
 * Money written the way a person reads it, not the way the ledger stores it.
 *
 * Two screens need this: a printed receipt, and an error message shown to a
 * cashier mid-sale. Both must agree with the stored figure exactly, so the
 * formatting is one operation in one place rather than two that can drift.
 *
 * Everything goes through bcmath from start to finish. `number_format()` takes a
 * float, and a float is how a total starts disagreeing with the DECIMAL(20,4) it
 * came from.
 */
final class MoneyFormat
{
    /**
     * Group the amount into the currency's minor units and put its local sign in
     * front — "Rp 55.500", "-US$ 12.50".
     */
    public static function format(?string $amount, ?string $currency): string
    {
        $decimals = self::decimals($currency);
        $value = DecimalMath::add((string) ($amount ?? '0'), '0', $decimals);
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;

        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        $display = self::group($whole).($decimals > 0 ? '.'.$fraction : '');

        return ($negative ? '-' : '').self::symbol($currency).' '.$display;
    }

    /**
     * The same amount without a currency sign, for a line already headed "Total
     * (IDR)" or a message that has named the currency.
     */
    public static function amount(?string $amount, ?string $currency): string
    {
        return self::group(
            explode('.', DecimalMath::add((string) ($amount ?? '0'), '0', self::decimals($currency)))[0]
        );
    }

    /**
     * The sign a customer expects on paper read across a counter. Unknown codes
     * print as themselves rather than being guessed at.
     */
    public static function symbol(?string $currency): string
    {
        return match (strtolower((string) $currency)) {
            'idr' => 'Rp',
            'usd' => 'US$',
            'eur' => '€',
            'gbp' => '£',
            default => trim((string) $currency),
        };
    }

    /**
     * IDR, and every currency the seed data ships with, has no minor units. The
     * list exists so a two-decimal currency renders correctly instead of
     * silently dropping cents.
     */
    public static function decimals(?string $currency): int
    {
        return in_array(strtolower((string) $currency), ['usd', 'eur', 'gbp', 'sgd', 'myr', 'aud'], true) ? 2 : 0;
    }

    /**
     * Thousands separators on a digit string, grouped with the dot Indonesian
     * money reads by.
     */
    public static function group(string $digits): string
    {
        return strrev(implode('.', str_split(strrev($digits), 3)));
    }
}
