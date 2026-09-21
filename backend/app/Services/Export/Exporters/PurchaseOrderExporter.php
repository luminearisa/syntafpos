<?php

namespace App\Services\Export\Exporters;

use App\Models\PurchaseOrder;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class PurchaseOrderExporter extends Exporter
{
    public function entity(): string
    {
        return 'purchase-orders';
    }

    public function permission(): string
    {
        return 'purchases.view';
    }

    public function columns(): array
    {
        return [
            'number', 'supplier', 'warehouse', 'order_date', 'expected_date',
            'status', 'currency', 'subtotal', 'item_discount_total',
            'discount_total', 'tax_total', 'shipping_cost', 'other_charges',
            'grand_total', 'notes', 'created_at',
        ];
    }

    public function filters(): array
    {
        return ['supplier_id', 'warehouse_id', 'status', 'from_date', 'to_date'];
    }

    public function query(Request $request, int $companyId): Builder
    {
        return PurchaseOrder::query()
            ->visibleTo($request->user())
            ->where('company_id', $companyId)
            ->with(['supplier:id,company_id,name', 'warehouse:id,company_id,code'])
            ->when($request->supplier_id, fn (Builder $query, int $id) => $query->where('supplier_id', $id))
            ->when($request->warehouse_id, fn (Builder $query, int $id) => $query->where('warehouse_id', $id))
            ->when($request->status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->from_date, fn (Builder $query, string $date) => $query->where('order_date', '>=', $date))
            ->when($request->to_date, fn (Builder $query, string $date) => $query->where('order_date', '<=', $date));
    }

    public function map(Model $record, Request $request): array
    {
        /** @var PurchaseOrder $record */

        return [
            $record->number,
            $record->supplier?->name,
            $record->warehouse?->code,
            $record->order_date?->toDateString(),
            $record->expected_date?->toDateString(),
            $record->status->value,
            $record->currency,
            $record->subtotal,
            $record->item_discount_total,
            $record->discount_total,
            $record->tax_total,
            $record->shipping_cost,
            $record->other_charges,
            $record->grand_total,
            $record->notes,
            $record->created_at?->toDateTimeString(),
        ];
    }
}
