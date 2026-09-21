<?php

namespace App\Services\Import\Importers;

use App\Models\Supplier;
use App\Services\Import\ImportContext;
use App\Services\Import\Importer;
use App\Services\NumberingService;

/**
 * Suppliers. As with customers, a blank code is issued by the numbering engine
 * rather than invented here.
 */
final class SupplierImporter extends Importer
{
    public function __construct(private NumberingService $numbering) {}

    public function entity(): string
    {
        return 'supplier';
    }

    public function permission(): string
    {
        return 'suppliers.create';
    }

    public function requiredColumns(): array
    {
        return ['name'];
    }

    public function optionalColumns(): array
    {
        return [
            'supplier_code', 'company_name', 'contact_person', 'phone', 'email',
            'address', 'city', 'province', 'country', 'postal_code', 'tax_number',
            'payment_terms', 'credit_limit', 'bank_name', 'bank_account',
            'bank_account_name', 'notes', 'status',
        ];
    }

    public function validateRow(array $row, ImportContext $context): array
    {
        $errors = [];

        $values = [
            'company_id' => $context->companyId,
            'supplier_code' => $this->uniqueValue($row, 'supplier_code', $context, 'supplier code', 'suppliers', 'supplier_code', $errors, required: false),
            'name' => $this->requireValue($row, 'name', $errors),
            'company_name' => $this->value($row, 'company_name'),
            'contact_person' => $this->value($row, 'contact_person'),
            'phone' => $this->value($row, 'phone'),
            'email' => $this->uniqueEmail($row, 'email', $context, 'suppliers', $errors),
            'address' => $this->value($row, 'address'),
            'city' => $this->value($row, 'city'),
            'province' => $this->value($row, 'province'),
            'country' => $this->value($row, 'country'),
            'postal_code' => $this->value($row, 'postal_code'),
            'tax_number' => $this->value($row, 'tax_number'),
            'payment_terms' => $this->nonNegativeInteger($row, 'payment_terms', $errors),
            'credit_limit' => $this->decimal($row, 'credit_limit', $errors),
            'bank_name' => $this->value($row, 'bank_name'),
            'bank_account' => $this->value($row, 'bank_account'),
            'bank_account_name' => $this->value($row, 'bank_account_name'),
            'notes' => $this->value($row, 'notes'),
            'status' => $this->value($row, 'status'),
        ];

        return $this->row(array_filter($values, static fn (mixed $value): bool => $value !== null), $errors);
    }

    public function commitRow(array $row, ImportContext $context): Supplier
    {
        if (empty($row['supplier_code'])) {
            $row['supplier_code'] = $this->numbering->next('supplier_code', $context->companyId, null, ['prefix' => 'SUP']);
        }

        return Supplier::create($row);
    }
}
