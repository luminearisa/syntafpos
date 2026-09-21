<?php

namespace App\Services\Export\Exporters;

use App\Models\GoodsReceipt;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class GoodsReceiptExporter extends Exporter
{
    public function entity(): string
    {
        return 'goods-receipts';
    }

    public function permission(): string
    {
        return 'purchases.view';
    }

    public function columns(): array
    {
        return [
            'number', 'supplier', 'warehouse', 'purchase_order', 'receipt_date',
            'status', 'posted_at', 'received_by', 'notes', 'created_at',
        ];
    }

    public function filters(): array
    {
        return ['supplier_id', 'warehouse_id', 'purchase_order_id', 'status', 'from_date', 'to_date'];
    }

    public function query(Request $request, int $companyId): Builder
    {
        return GoodsReceipt::query()
            ->visibleTo($request->user())
            ->where('company_id', $companyId)
            ->with([
                'supplier:id,company_id,name',
                'warehouse:id,company_id,code',
                'purchaseOrder:id,company_id,number',
                'receivedBy:id,name',
            ])
            ->when($request->supplier_id, fn (Builder $query, int $id) => $query->where('supplier_id', $id))
            ->when($request->warehouse_id, fn (Builder $query, int $id) => $query->where('warehouse_id', $id))
            ->when($request->purchase_order_id, fn (Builder $query, int $id) => $query->where('purchase_order_id', $id))
            ->when($request->status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->from_date, fn (Builder $query, string $date) => $query->where('receipt_date', '>=', $date))
            ->when($request->to_date, fn (Builder $query, string $date) => $query->where('receipt_date', '<=', $date));
    }

    public function map(Model $record, Request $request): array
    {
        /** @var GoodsReceipt $record */

        return [
            $record->number,
            $record->supplier?->name,
            $record->warehouse?->code,
            $record->purchaseOrder?->number,
            $record->receipt_date?->toDateString(),
            $record->status->value,
            $record->posted_at?->toDateTimeString(),
            $record->receivedBy?->name,
            $record->notes,
            $record->created_at?->toDateTimeString(),
        ];
    }
}
