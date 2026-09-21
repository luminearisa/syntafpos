<?php

namespace App\Services\Import\Importers;

use App\Enums\ProductType;
use App\Models\Product;
use App\Services\Import\ImportContext;
use App\Services\Import\Importer;

/**
 * Product master data.
 *
 * SKU is the file's key: unique per company, and unique within the file. The
 * catalog relations are referenced by code so an export can be re-imported,
 * and by id for files prepared from the application itself.
 */
final class ProductImporter extends Importer
{
    public function entity(): string
    {
        return 'product';
    }

    public function permission(): string
    {
        return 'products.create';
    }

    public function requiredColumns(): array
    {
        return ['sku', 'name'];
    }

    public function optionalColumns(): array
    {
        return [
            'barcode', 'category_code', 'category_id', 'brand_code', 'brand_id',
            'unit_code', 'product_type', 'cost_price', 'selling_price',
            'minimum_stock', 'maximum_stock', 'reorder_point', 'reorder_quantity',
            'description', 'is_active', 'track_inventory',
        ];
    }

    public function validateRow(array $row, ImportContext $context): array
    {
        $errors = [];

        $values = [
            'company_id' => $context->companyId,
            'sku' => $this->uniqueValue($row, 'sku', $context, 'sku', 'products', 'sku', $errors),
            'name' => $this->requireValue($row, 'name', $errors),
            'barcode' => $this->value($row, 'barcode'),
            'category_id' => $this->resolveId($row, 'category_id', $context, 'categories', 'category', $errors)
                ?? $this->resolveByCode($row, 'category_code', $context, 'categories', 'category', $errors),
            'brand_id' => $this->resolveId($row, 'brand_id', $context, 'brands', 'brand', $errors)
                ?? $this->resolveByCode($row, 'brand_code', $context, 'brands', 'brand', $errors),
            'default_unit_id' => $this->resolveByCode($row, 'unit_code', $context, 'units', 'unit', $errors),
            'product_type' => $this->enum($row, 'product_type', $errors, ProductType::class, ProductType::Simple),
            'cost_price' => $this->decimal($row, 'cost_price', $errors),
            'selling_price' => $this->decimal($row, 'selling_price', $errors),
            'minimum_stock' => $this->decimal($row, 'minimum_stock', $errors),
            'maximum_stock' => $this->decimal($row, 'maximum_stock', $errors),
            'reorder_point' => $this->decimal($row, 'reorder_point', $errors),
            'reorder_quantity' => $this->decimal($row, 'reorder_quantity', $errors),
            'description' => $this->value($row, 'description'),
            'is_active' => $this->flag($row, 'is_active', $errors),
            'track_inventory' => $this->flag($row, 'track_inventory', $errors),
        ];

        return $this->row($this->compact($values), $errors);
    }

    public function commitRow(array $row, ImportContext $context): Product
    {
        return Product::create($row);
    }

    /**
     * Drop the absent columns: an unset key falls back to the column default,
     * which is what a file that omits the reorder policy should express.
     */
    private function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }
}
