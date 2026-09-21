<?php

namespace App\Services\Export\Exporters;

use App\Models\Product;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class ProductExporter extends Exporter
{
    public function entity(): string
    {
        return 'products';
    }

    public function permission(): string
    {
        return 'products.view';
    }

    public function columns(): array
    {
        return [
            'sku', 'barcode', 'name', 'product_type', 'category', 'brand', 'unit',
            'cost_price', 'selling_price', 'is_active', 'track_inventory',
            'reorder_point', 'minimum_stock', 'created_at',
        ];
    }

    public function filters(): array
    {
        return ['search', 'category_id', 'brand_id', 'product_type', 'is_active'];
    }

    public function query(Request $request, int $companyId): Builder
    {
        return Product::query()
            ->visibleTo($request->user())
            ->where('company_id', $companyId)
            ->with([
                'category:id,company_id,name',
                'brand:id,company_id,name',
                'defaultUnit:id,company_id,code',
            ])
            ->when($request->search, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->category_id, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->when($request->brand_id, fn (Builder $query, int $id) => $query->where('brand_id', $id))
            ->when($request->product_type, fn (Builder $query, string $type) => $query->where('product_type', $type))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')));
    }

    public function map(Model $record, Request $request): array
    {
        /** @var Product $record */

        return [
            $record->sku,
            $record->barcode,
            $record->name,
            $record->product_type->value,
            $record->category?->name,
            $record->brand?->name,
            $record->defaultUnit?->code,
            $record->cost_price,
            $record->selling_price,
            $record->is_active ? '1' : '0',
            $record->track_inventory ? '1' : '0',
            $record->reorder_point,
            $record->minimum_stock,
            $record->created_at?->toDateTimeString(),
        ];
    }
}
