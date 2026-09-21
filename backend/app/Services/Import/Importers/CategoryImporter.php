<?php

namespace App\Services\Import\Importers;

use App\Enums\EntityStatus;
use App\Models\Category;
use App\Services\Import\ImportContext;
use App\Services\Import\Importer;

/**
 * Catalog categories. Code is unique per company; parents are referenced by
 * code and must already exist, so the file is read top-down.
 */
final class CategoryImporter extends Importer
{
    public function entity(): string
    {
        return 'category';
    }

    public function permission(): string
    {
        return 'categories.create';
    }

    public function requiredColumns(): array
    {
        return ['code', 'name'];
    }

    public function optionalColumns(): array
    {
        return ['parent_code', 'description', 'level', 'sort_order', 'status'];
    }

    public function validateRow(array $row, ImportContext $context): array
    {
        $errors = [];

        $values = [
            'company_id' => $context->companyId,
            'code' => $this->uniqueValue($row, 'code', $context, 'category code', 'categories', 'code', $errors),
            'name' => $this->requireValue($row, 'name', $errors),
            'parent_id' => $this->resolveByCode($row, 'parent_code', $context, 'categories', 'parent category', $errors),
            'description' => $this->value($row, 'description'),
            'level' => $this->nonNegativeInteger($row, 'level', $errors),
            'sort_order' => $this->nonNegativeInteger($row, 'sort_order', $errors),
            'status' => $this->enum($row, 'status', $errors, EntityStatus::class, EntityStatus::Active)?->value,
        ];

        return $this->row(array_filter($values, static fn (mixed $value): bool => $value !== null), $errors);
    }

    public function commitRow(array $row, ImportContext $context): Category
    {
        return Category::create($row);
    }
}
