<?php

namespace App\Services\Export\Exporters;

use App\Models\StockMovement;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The stock ledger: the highest-volume table in the application (§43), so the
 * export streams the query rather than materialising it.
 */
final class StockMovementExporter extends Exporter
{
    public function entity(): string
    {
        return 'stock-movements';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function columns(): array
    {
        return [
            'occurred_at', 'movement_type', 'product_sku', 'product_name',
            'warehouse_code', 'unit_code', 'quantity', 'unit_cost', 'total_cost',
            'balance_after', 'reference', 'created_by', 'notes',
        ];
    }

    public function filters(): array
    {
        return ['warehouse_id', 'product_id', 'movement_type', 'from_date', 'to_date'];
    }

    public function query(Request $request, int $companyId): Builder
    {
        return StockMovement::query()
            ->visibleTo($request->user())
            ->where('company_id', $companyId)
            ->with([
                'product:id,company_id,sku,name',
                'warehouse:id,company_id,code',
                'unit:id,company_id,code',
                'creator:id,name',
            ])
            ->when($request->warehouse_id, fn (Builder $query, int $id) => $query->where('warehouse_id', $id))
            ->when($request->product_id, fn (Builder $query, int $id) => $query->where('product_id', $id))
            ->when($request->movement_type, fn (Builder $query, string $type) => $query->where('movement_type', $type))
            ->when($request->from_date, fn (Builder $query, string $date) => $query->where('occurred_at', '>=', $date))
            ->when($request->to_date, fn (Builder $query, string $date) => $query->where('occurred_at', '<=', $date.' 23:59:59'));
    }

    public function map(Model $record, Request $request): array
    {
        /** @var StockMovement $record */

        return [
            $record->occurred_at?->toDateTimeString(),
            $record->movement_type->value,
            $record->product?->sku,
            $record->product?->name,
            $record->warehouse?->code,
            $record->unit?->code,
            $record->quantity,
            $record->unit_cost,
            $record->total_cost,
            $record->balance_after,
            $record->reference_type ? class_basename($record->reference_type).'#'.$record->reference_id : null,
            $record->creator?->name,
            $record->notes,
        ];
    }
}
