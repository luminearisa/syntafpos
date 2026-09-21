<?php

namespace App\Services\Export\Exporters;

use App\Models\StockBalance;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Current stock, from the balance cache the inventory engine maintains.
 *
 * On hand and its valuation are reported exactly as the ledger stores them,
 * never as a rounded number, and available is the balance's own derivation so
 * the export cannot disagree with the inventory report.
 */
final class StockExporter extends Exporter
{
    public function entity(): string
    {
        return 'stock';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function columns(): array
    {
        return [
            'product_sku', 'product_name', 'warehouse_code', 'warehouse_name',
            'unit_code', 'on_hand', 'reserved', 'available',
            'average_cost', 'last_cost', 'last_movement_at',
        ];
    }

    public function filters(): array
    {
        return ['warehouse_id', 'product_id', 'location_id'];
    }

    public function query(Request $request, int $companyId): Builder
    {
        return StockBalance::query()
            ->visibleTo($request->user())
            ->where('company_id', $companyId)
            ->with([
                'product:id,company_id,sku,name',
                'warehouse:id,company_id,code,name',
                'unit:id,company_id,code',
            ])
            ->when($request->warehouse_id, fn (Builder $query, int $id) => $query->where('warehouse_id', $id))
            ->when($request->product_id, fn (Builder $query, int $id) => $query->where('product_id', $id))
            ->when($request->location_id, fn (Builder $query, int $id) => $query->where('location_id', $id));
    }

    public function map(Model $record, Request $request): array
    {
        /** @var StockBalance $record */

        return [
            $record->product?->sku,
            $record->product?->name,
            $record->warehouse?->code,
            $record->warehouse?->name,
            $record->unit?->code,
            $record->on_hand,
            $record->reserved,
            $record->available(),
            $record->average_cost,
            $record->last_cost,
            $record->last_movement_at?->toDateTimeString(),
        ];
    }
}
