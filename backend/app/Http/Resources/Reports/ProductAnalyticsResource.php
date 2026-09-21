<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One product's analytics row (spec §35).
 *
 * Every figure is derived from the stock ledger and the purchasing documents;
 * a product that has never been received reports the catalog cost and price
 * with a zero balance, never a hardcoded demo figure.
 *
 * @mixin array
 */
class ProductAnalyticsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'product_id' => $row['product_id'],
            'sku' => $row['sku'],
            'name' => $row['name'],
            'is_active' => $row['is_active'],

            'total_stock' => $row['total_stock'],
            'current_cost' => $row['current_cost'],
            'current_price' => $row['current_price'],
            'estimated_margin' => $row['estimated_margin'],
            'estimated_margin_percent' => $row['estimated_margin_percent'],

            'last_purchase_date' => $row['last_purchase_date'],
            'last_purchase_quantity' => $row['last_purchase_quantity'],
            'last_supplier_id' => $row['last_supplier_id'],
            'last_supplier_name' => $row['last_supplier_name'],

            'movement_count' => $row['movement_count'],
            'last_movement_at' => $row['last_movement_at'],
            'days_since_last_movement' => $row['days_since_last_movement'],
        ];
    }
}
