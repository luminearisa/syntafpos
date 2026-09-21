<?php

namespace App\Services\Import\Importers;

use App\Enums\EntityStatus;
use App\Models\Brand;
use App\Services\Import\ImportContext;
use App\Services\Import\Importer;

final class BrandImporter extends Importer
{
    public function entity(): string
    {
        return 'brand';
    }

    public function permission(): string
    {
        return 'brands.create';
    }

    public function requiredColumns(): array
    {
        return ['code', 'name'];
    }

    public function optionalColumns(): array
    {
        return ['description', 'logo', 'status'];
    }

    public function validateRow(array $row, ImportContext $context): array
    {
        $errors = [];

        $values = [
            'company_id' => $context->companyId,
            'code' => $this->uniqueValue($row, 'code', $context, 'brand code', 'brands', 'code', $errors),
            'name' => $this->requireValue($row, 'name', $errors),
            'description' => $this->value($row, 'description'),
            'logo' => $this->value($row, 'logo'),
            'status' => $this->enum($row, 'status', $errors, EntityStatus::class, EntityStatus::Active)?->value,
        ];

        return $this->row(array_filter($values, static fn (mixed $value): bool => $value !== null), $errors);
    }

    public function commitRow(array $row, ImportContext $context): Brand
    {
        return Brand::create($row);
    }
}
