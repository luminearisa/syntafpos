<?php

namespace App\Support;

/**
 * Fixed-point money arithmetic.
 *
 * Every figure the API reports is derived with bcmath: floating point drift
 * on a cost basis or a margin would silently misstate inventory valuation and
 * pricing across thousands of rows.
 */
final class DecimalMath
{
    public static function add(?string $a, ?string $b, int $scale = 4): string
    {
        return bcadd((string) ($a ?? '0'), (string) ($b ?? '0'), $scale);
    }

    public static function sub(?string $a, ?string $b, int $scale = 4): string
    {
        return bcsub((string) ($a ?? '0'), (string) ($b ?? '0'), $scale);
    }

    public static function mul(?string $a, ?string $b, int $scale = 4): string
    {
        return bcmul((string) ($a ?? '0'), (string) ($b ?? '0'), $scale);
    }

    /**
     * Half-up division. bcmath truncates, which would systematically understate
     * a cost basis or a margin, so the guard digit is dropped with rounding.
     */
    public static function div(?string $numerator, ?string $denominator, int $scale = 4): string
    {
        if (bccomp((string) ($denominator ?? '0'), '0', max($scale, 6)) === 0) {
            return '0';
        }

        $exponent = bcpow('10', (string) ($scale + 1));
        $scaled = bcmul(bcdiv((string) ($numerator ?? '0'), (string) ($denominator ?? '0'), $scale + 1), $exponent, 0);

        $truncated = bcdiv($scaled, '10', 0);
        $remainder = bcmod($scaled, '10');

        if (bccomp($remainder, '5', 0) >= 0) {
            $truncated = bcadd($truncated, '1', 0);
        }

        return bcdiv($truncated, bcpow('10', (string) $scale), $scale);
    }

    /**
     * Weighted average of a quantity/value pair, half-up at the given scale.
     */
    public static function weightedAverage(string $onHand, string $value, int $scale = 4): string
    {
        return self::div($value, $onHand, $scale);
    }

    /**
     * Margin of a selling price over a cost basis, as an absolute amount.
     */
    public static function margin(string $sellingPrice, string $cost): string
    {
        return self::sub($sellingPrice, $cost, 4);
    }

    /**
     * Margin of a selling price over a cost basis, as a percentage of the price.
     */
    public static function marginPercent(string $sellingPrice, string $cost): string
    {
        if (bccomp($sellingPrice, '0', 4) <= 0) {
            return '0';
        }

        // Scaled through div() rather than bcmul() so the percentage keeps the
        // half-up rounding instead of bcmul's truncation.
        return self::div(self::mul(self::margin($sellingPrice, $cost), '100', 4), $sellingPrice, 2);
    }
}
