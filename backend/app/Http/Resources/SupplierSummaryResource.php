<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transaction summary of one supplier, computed on demand.
 *
 * Every figure is aggregated from purchasing rows that really exist; nothing is
 * stored or invented. Phase 2 ships no payment engine, so `paid` is always zero
 * until payments exist to sum.
 *
 * @mixin array
 */
class SupplierSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'supplier_id' => $summary['supplier_id'],
            'supplier_code' => $summary['supplier_code'],
            'supplier_name' => $summary['supplier_name'],
            'payment_terms' => $summary['payment_terms'],

            'total_purchase' => $this->money($summary['total_purchase']),
            'total_return' => $this->money($summary['total_return']),
            // No payment engine exists in Phase 2, so nothing has been paid.
            'paid' => $this->money($summary['paid']),
            'outstanding' => $this->money($summary['outstanding']),

            'purchase_count' => $summary['purchase_count'],
            'last_purchase_date' => $summary['last_purchase_date'],
            'due_date' => $summary['due_date'],
        ];
    }

    /**
     * Money is emitted as an exact 4-digit decimal string, matching the column
     * scale, so figures never degrade through float rounding.
     */
    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 4);
    }
}
