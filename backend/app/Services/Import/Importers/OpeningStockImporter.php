<?php

namespace App\Services\Import\Importers;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Import\ImportContext;
use App\Services\Import\Importer;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock: quantities already on the shelf when the ledger starts.
 *
 * Unlike the master-data importers the rows must reference products and
 * warehouses that already exist, and committing a row posts a real movement
 * through the inventory engine rather than inserting a table row. The product
 * a balance belongs to is the movement's reference, so an opening balance is
 * traceable without inventing a document for it.
 */
final class OpeningStockImporter extends Importer
{
    public function __construct(private InventoryService $inventory) {}

    public function entity(): string
    {
        return 'opening_stock';
    }

    public function permission(): string
    {
        return 'inventory.adjust';
    }

    public function requiredColumns(): array
    {
        return ['product_sku', 'warehouse_code', 'quantity'];
    }

    public function optionalColumns(): array
    {
        return ['unit_code', 'unit_cost', 'location_code', 'notes'];
    }

    public function validateRow(array $row, ImportContext $context): array
    {
        $errors = [];

        $product = $this->product($row, $context, $errors);
        $warehouse = $this->warehouse($row, $context, $errors);

        $unitId = $this->resolveByCode($row, 'unit_code', $context, 'units', 'unit', $errors)
            ?? $product?->default_unit_id;

        if ($unitId === null) {
            $this->addError($errors, 'unit_code', 'The unit_code is required because the product has no default unit.');
        }

        $locationId = $this->locationId($row, $warehouse, $errors);

        $quantity = $this->positiveDecimal($row, 'quantity', $errors);
        $unitCost = $this->decimal($row, 'unit_cost', $errors) ?? $this->cost($product);

        // One opening balance per product, warehouse, unit and location: a
        // second row for the same cell would double-count the shelf.
        if ($product && $warehouse && $unitId !== null && $quantity !== null) {
            $cell = implode('|', [(int) $product->id, (int) $warehouse->id, (int) $unitId, (int) ($locationId ?? 0)]);

            if (! $context->claim('opening_stock', $cell)) {
                $this->addError($errors, 'product_sku', 'This product, warehouse, unit and location already has an opening balance in this file.');
            }
        }

        return $this->row(array_filter([
            'company_id' => $context->companyId,
            'product_id' => $product?->id,
            'warehouse_id' => $warehouse?->id,
            'branch_id' => $warehouse?->branch_id,
            'location_id' => $locationId,
            'unit_id' => $unitId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'notes' => $this->value($row, 'notes'),
        ], static fn (mixed $value): bool => $value !== null), $errors);
    }

    public function commitRow(array $row, ImportContext $context): StockMovement
    {
        /** @var Product $product */
        $product = Product::query()
            ->where('company_id', $context->companyId)
            ->where('id', $row['product_id'])
            ->firstOrFail();

        return $this->inventory->move(
            [
                'product_id' => $row['product_id'],
                'product_variant_id' => null,
                'unit_id' => $row['unit_id'],
                'quantity' => $row['quantity'],
            ],
            MovementType::Opening,
            [
                'company_id' => $context->companyId,
                'branch_id' => $row['branch_id'] ?? null,
                'warehouse_id' => $row['warehouse_id'],
                'location_id' => $row['location_id'] ?? null,
            ],
            $product,
            $row['unit_cost'] ?? '0',
            $context->userId,
            $row['notes'] ?? 'Opening stock import'
        );
    }

    /**
     * Resolve the product the balance is for, inside the importing company.
     */
    private function product(array $row, ImportContext $context, array &$errors): ?Product
    {
        $sku = $this->requireValue($row, 'product_sku', $errors);

        if ($sku === null) {
            return null;
        }

        if (! array_key_exists($sku, $this->codeCache['products'] ?? [])) {
            $this->codeCache['products'][$sku] = Product::query()
                ->where('company_id', $context->companyId)
                ->where('sku', $sku)
                ->value('id');
        }

        $id = $this->codeCache['products'][$sku];

        if ($id === null) {
            $this->addError($errors, 'product_sku', "No product with the sku '{$sku}' exists in this company.");

            return null;
        }

        return Product::query()->find($id);
    }

    private function warehouse(array $row, ImportContext $context, array &$errors): ?Warehouse
    {
        $code = $this->requireValue($row, 'warehouse_code', $errors);

        if ($code === null) {
            return null;
        }

        if (! array_key_exists($code, $this->codeCache['warehouses'] ?? [])) {
            $this->codeCache['warehouses'][$code] = Warehouse::query()
                ->where('company_id', $context->companyId)
                ->where('code', $code)
                ->value('id');
        }

        $id = $this->codeCache['warehouses'][$code];

        if ($id === null) {
            $this->addError($errors, 'warehouse_code', "No warehouse with the code '{$code}' exists in this company.");

            return null;
        }

        return Warehouse::query()->find($id);
    }

    /**
     * Resolve a storage location inside the warehouse already chosen, so a code
     * from another warehouse is rejected rather than silently accepted.
     */
    private function locationId(array $row, ?Warehouse $warehouse, array &$errors): ?int
    {
        $code = $this->value($row, 'location_code');

        if ($code === null) {
            return null;
        }

        if (! $warehouse) {
            return null;
        }

        $cacheKey = 'warehouse_locations|'.$warehouse->id;

        if (! array_key_exists($code, $this->codeCache[$cacheKey] ?? [])) {
            $this->codeCache[$cacheKey][$code] = DB::table('warehouse_locations')
                ->where('warehouse_id', $warehouse->id)
                ->where('code', $code)
                ->value('id');
        }

        $id = $this->codeCache[$cacheKey][$code];

        if ($id === null) {
            $this->addError($errors, 'location_code', "No location with the code '{$code}' exists in warehouse '{$warehouse->code}'.");

            return null;
        }

        return (int) $id;
    }

    /**
     * The cost basis of the opening quantity, defaulting to the product's own
     * cost so a valuation import can omit the column.
     */
    private function cost(?Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        $cost = (string) $product->cost_price;

        return bccomp($cost, '0', 4) === 0 ? null : $cost;
    }
}
