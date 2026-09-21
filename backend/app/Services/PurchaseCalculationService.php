<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\PurchaseOrder;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Fixed-point money engine for purchase documents.
 *
 * Every figure is derived with bcmath through DecimalMath: a float anywhere in
 * this chain would silently misstate a grand total across thousands of orders.
 * Totals are recomputed from the line data on every write; client-sent totals
 * are never trusted and are always overwritten.
 *
 * Line model (spec §23):
 *   gross      = quantity x unit_price
 *   discount   = amount, or percent of the gross line
 *   net_price  = gross - discount
 *   tax_amount = net_price x tax_rate / 100   (tax is exclusive in Phase 2)
 *   subtotal   = net_price + tax_amount
 *
 * Header model:
 *   subtotal            = sum of gross lines
 *   item_discount_total = sum of line discounts
 *   grand_total         = subtotal - item_discount_total - discount_total
 *                        + tax_total + shipping_cost + other_charges
 *
 * The header discount_total is a flat amount applied after the item discounts.
 * Tax is computed against the line net only, before the header discount, so a
 * later header-discount edit does not rewrite the taxed line history.
 */
class PurchaseCalculationService
{
    private const TAX_DIVISOR = '100';

    /**
     * Compute every line and the header aggregates those lines drive.
     *
     * Each entry may come straight from a validated request or from a persisted
     * item; the numeric keys are read as strings and discount_type accepts both
     * the enum and its raw value.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{
     *     items: list<array{net_price: string, discount_amount: string, tax_amount: string, subtotal: string}>,
     *     subtotal: string,
     *     item_discount_total: string,
     *     tax_total: string,
     * }
     */
    public function calculateItems(Collection $items): array
    {
        $subtotal = '0';
        $itemDiscountTotal = '0';
        $taxTotal = '0';
        $computed = [];

        foreach ($items as $item) {
            $quantity = (string) ($item['quantity'] ?? '0');
            $unitPrice = (string) ($item['unit_price'] ?? '0');
            $discount = (string) ($item['discount'] ?? '0');
            $taxRate = (string) ($item['tax_rate'] ?? '0');

            $rawType = $item['discount_type'] ?? DiscountType::Amount->value;
            $discountType = $rawType instanceof DiscountType ? $rawType : DiscountType::from((string) $rawType);

            $gross = DecimalMath::mul($quantity, $unitPrice);

            // A percentage discount is taken off the gross line; a flat amount
            // is subtracted as-is.
            $discountAmount = $discountType === DiscountType::Percent
                ? DecimalMath::div(DecimalMath::mul($gross, $discount), self::TAX_DIVISOR)
                : DecimalMath::add($discount, '0');

            $netPrice = DecimalMath::sub($gross, $discountAmount);

            // Exclusive tax: the rate applies to the net line only.
            $taxAmount = DecimalMath::div(DecimalMath::mul($netPrice, $taxRate), self::TAX_DIVISOR);

            $computed[] = [
                'net_price' => $netPrice,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'subtotal' => DecimalMath::add($netPrice, $taxAmount),
            ];

            $subtotal = DecimalMath::add($subtotal, $gross);
            $itemDiscountTotal = DecimalMath::add($itemDiscountTotal, $discountAmount);
            $taxTotal = DecimalMath::add($taxTotal, $taxAmount);
        }

        return [
            'items' => $computed,
            'subtotal' => $subtotal,
            'item_discount_total' => $itemDiscountTotal,
            'tax_total' => $taxTotal,
        ];
    }

    /**
     * Recompute every header money field from the order's persisted lines.
     *
     * Reads the stored discount/shipping/other charges off the header and the
     * line data off the items, so the returned array is the single source of
     * truth for what the columns should hold.
     *
     * @return array{
     *     subtotal: string,
     *     item_discount_total: string,
     *     discount_total: string,
     *     tax_total: string,
     *     shipping_cost: string,
     *     other_charges: string,
     *     grand_total: string,
     * }
     */
    public function calculateTotals(PurchaseOrder $po): array
    {
        // Reload the lines so a createMany in the same transaction is reflected.
        $po->load('items');

        $computed = $this->calculateItems($po->items);

        $discountTotal = (string) ($po->discount_total ?? '0');
        $shippingCost = (string) ($po->shipping_cost ?? '0');
        $otherCharges = (string) ($po->other_charges ?? '0');

        // Header discount lands after the item discounts, then tax, shipping
        // and the other charges are added on top of the discounted net.
        $net = DecimalMath::sub(
            DecimalMath::sub($computed['subtotal'], $computed['item_discount_total']),
            $discountTotal
        );

        $grandTotal = $net;
        $grandTotal = DecimalMath::add($grandTotal, $computed['tax_total']);
        $grandTotal = DecimalMath::add($grandTotal, $shippingCost);
        $grandTotal = DecimalMath::add($grandTotal, $otherCharges);

        return [
            'subtotal' => $computed['subtotal'],
            'item_discount_total' => $computed['item_discount_total'],
            'discount_total' => $discountTotal,
            'tax_total' => $computed['tax_total'],
            'shipping_cost' => $shippingCost,
            'other_charges' => $otherCharges,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Write the recomputed header totals onto the order and persist them.
     *
     * Called after every store/update so the stored money columns are always
     * the derived ones, never the values a client happened to send.
     */
    public function applyTotals(PurchaseOrder $po): void
    {
        $po->forceFill($this->calculateTotals($po))->save();
    }
}
