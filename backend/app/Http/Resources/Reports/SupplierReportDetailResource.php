<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One supplier's drill-down (spec §34): the summary block shared with the list
 * report, followed by the document history that makes it up. Each history
 * section is paginated on its own, with its page metadata in the response meta.
 *
 * @mixin array
 */
class SupplierReportDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $supplier = $this->resource['supplier'];

        return [
            'supplier' => [
                'id' => $supplier->id,
                'supplier_code' => $supplier->supplier_code,
                'name' => $supplier->name,
                'payment_terms' => $supplier->payment_terms,
                'status' => $supplier->status,
            ],
            'summary' => new SupplierReportSummaryResource($this->resource['summary']),
            'purchase_orders' => SupplierReportOrderResource::collection($this->resource['orders']),
            'goods_receipts' => SupplierReportReceiptResource::collection($this->resource['receipts']),
            'purchase_returns' => SupplierReportReturnResource::collection($this->resource['returns']),
        ];
    }
}
