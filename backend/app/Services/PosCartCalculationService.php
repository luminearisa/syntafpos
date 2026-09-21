<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\TaxType;
use App\Models\PosCart;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Fixed-point money engine for point-of-sale carts.
 *
 * Same contract as PurchaseCalculationService: every figure is derived with
 * bcmath through DecimalMath, and a client-sent total is never trusted. A
 * cashier's device computes a preview; the server recomputes the truth on
 * every write so checkout in Subphase 3.2 sees exactly what the till showed.
 *
 * Line model:
 *   gross        = quantity x unit_price
 *   discount     = amount, or percent of the gross line
 *   net          = gross - discount
 *   exclusive:   tax = net x rate / 100;            total = net + tax
 *   inclusive:   tax = net - net / (1 + rate/100);  total = net (tax carved out)
 *
 * Header model:
 *   subtotal     = sum of gross lines
 *   discount     = sum of line discounts, + the header cart discount
 *   tax          = sum of line tax amounts (inclusive tax is included in the
 *                  line total and reported for display, not added again)
 *   grand total  = subtotal - line discounts - header discount
 *                  + exclusive tax + other charges + rounding
 *
 * Rounding brings the raw total to the currency's smallest displayed unit (IDR
 * carries zero decimals, so 10.500 rounds to 11.000 half-up) and is stored
 * signed, so a receipt can show the customer exactly what was adjusted.
 */
class PosCartCalculationService
{
    private const PERCENT = '100';

    /**
     * Compute each line's money fields from its stored inputs.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{
     *     lines: list<array{discount_amount: string, tax_amount: string, line_subtotal: string, line_total: string}>,
     *     subtotal: string,
     *     item_discount_total: string,
     *     tax_total: string,
     *     inclusive_tax_total: string,
     * }
     */
    public function calculateLines(Collection $items): array
    {
        $subtotal = '0';
        $itemDiscountTotal = '0';
        $exclusiveTax = '0';
        $inclusiveTax = '0';
        $lines = [];

        foreach ($items as $item) {
            $quantity = (string) ($item['quantity'] ?? '0');
            $unitPrice = (string) ($item['unit_price'] ?? '0');
            $discount = (string) ($item['discount'] ?? '0');
            $taxRate = (string) ($item['tax_rate'] ?? '0');
            $inclusive = ($item['tax_mode'] ?? TaxType::Exclusive->value) === TaxType::Inclusive->value;

            $rawType = $item['discount_type'] ?? DiscountType::Amount->value;
            $discountType = $rawType instanceof DiscountType ? $rawType : DiscountType::from((string) $rawType);

            $gross = DecimalMath::mul($quantity, $unitPrice);

            $discountAmount = $discountType === DiscountType::Percent
                ? DecimalMath::div(DecimalMath::mul($gross, $discount), self::PERCENT)
                : DecimalMath::add($discount, '0');

            // A line discount can never exceed the line itself.
            if (bccomp($discountAmount, $gross, 4) > 0) {
                $discountAmount = $gross;
            }

            $net = DecimalMath::sub($gross, $discountAmount);

            if ($inclusive) {
                // The price already contains tax: carve it out of the net so the
                // line total stays what the customer pays, net of discount.
                $taxAmount = DecimalMath::sub(
                    $net,
                    DecimalMath::div(
                        DecimalMath::mul($net, self::PERCENT),
                        DecimalMath::add(self::PERCENT, $taxRate)
                    )
                );
            } else {
                $taxAmount = DecimalMath::div(DecimalMath::mul($net, $taxRate), self::PERCENT);
            }

            $lineTotal = $inclusive ? $net : DecimalMath::add($net, $taxAmount);

            $lines[] = [
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'line_subtotal' => $gross,
                'line_total' => $lineTotal,
            ];

            $subtotal = DecimalMath::add($subtotal, $gross);
            $itemDiscountTotal = DecimalMath::add($itemDiscountTotal, $discountAmount);

            if ($inclusive) {
                $inclusiveTax = DecimalMath::add($inclusiveTax, $taxAmount);
            } else {
                $exclusiveTax = DecimalMath::add($exclusiveTax, $taxAmount);
            }
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'item_discount_total' => $itemDiscountTotal,
            'tax_total' => DecimalMath::add($exclusiveTax, $inclusiveTax),
            'inclusive_tax_total' => $inclusiveTax,
        ];
    }

    /**
     * Recompute the header money fields from the cart's persisted lines.
     *
     * @return array<string, string> the columns a cart should hold
     */
    public function calculateTotals(PosCart $cart): array
    {
        // Reload so lines written moments earlier in the same request count.
        $cart->load('items');

        $computed = $this->calculateLines($cart->items);

        $headerDiscount = $this->headerDiscount($cart, $computed);
        $otherCharges = (string) ($cart->other_charges ?? '0');

        $exclusivePortion = DecimalMath::sub($computed['tax_total'], $computed['inclusive_tax_total']);

        // Rounding is applied against the raw total to the currency's smallest
        // displayed unit, then stored as a signed correction.
        $raw = DecimalMath::sub(
            DecimalMath::sub($computed['subtotal'], $computed['item_discount_total']),
            $headerDiscount
        );
        $raw = DecimalMath::add($raw, $exclusivePortion);
        $raw = DecimalMath::add($raw, $otherCharges);

        $rounding = DecimalMath::sub($this->roundToCurrency($raw), $raw);
        $grandTotal = DecimalMath::add($raw, $rounding);

        return [
            'subtotal' => $computed['subtotal'],
            'item_discount_total' => $computed['item_discount_total'],
            'discount_total' => $headerDiscount,
            'tax_total' => $computed['tax_total'],
            'tax_included_total' => $computed['inclusive_tax_total'],
            'other_charges' => $otherCharges,
            'rounding' => $rounding,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Write the derived totals onto the cart and persist them.
     */
    public function applyTotals(PosCart $cart): void
    {
        $cart->forceFill($this->calculateTotals($cart))->save();
    }

    /**
     * Resolve the header discount as an absolute amount.
     *
     * The cart holds the cashier's raw entry in discount_input plus the
     * discount_type that says how to read it. Resolving it into discount_total
     * on every recompute is why the input is stored separately: a percent
     * entered once must not be re-applied to its own result on the next edit.
     *
     * @param  array{subtotal: string, item_discount_total: string}  $computed
     */
    private function headerDiscount(PosCart $cart, array $computed): string
    {
        $discount = (string) ($cart->discount_input ?? '0');
        $type = $cart->discount_type ?? DiscountType::Amount;

        $netOfLines = DecimalMath::sub($computed['subtotal'], $computed['item_discount_total']);

        $resolved = $type === DiscountType::Percent
            ? DecimalMath::div(DecimalMath::mul($netOfLines, $discount), self::PERCENT)
            : $discount;

        if (bccomp($resolved, '0', 4) < 0) {
            return '0';
        }

        return bccomp($resolved, $netOfLines, 4) > 0 ? $netOfLines : $resolved;
    }

    /**
     * Round to the smallest displayed unit of the currency.
     *
     * IDR is seeded with zero currency decimals, so a 10.500 total lands on
     * 11.000; div() already rounds half-up at the target scale.
     */
    private function roundToCurrency(string $amount, int $decimals = 0): string
    {
        return DecimalMath::div($amount, '1', $decimals);
    }
}
