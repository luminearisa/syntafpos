<?php

namespace App\Services\Import\Importers;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\Import\ImportContext;
use App\Services\Import\Importer;
use App\Services\NumberingService;

/**
 * Customers. The code is unique per company but optional in a file: when the
 * column is blank the numbering engine issues one, so a till-side export of a
 * loyalty list imports without the caller having synthesised codes.
 */
final class CustomerImporter extends Importer
{
    public function __construct(private NumberingService $numbering) {}

    public function entity(): string
    {
        return 'customer';
    }

    public function permission(): string
    {
        return 'customers.create';
    }

    public function requiredColumns(): array
    {
        return ['name'];
    }

    public function optionalColumns(): array
    {
        return [
            'customer_code', 'type', 'phone', 'email', 'address', 'city', 'province',
            'country', 'postal_code', 'tax_number', 'credit_limit', 'payment_terms',
            'notes', 'is_active',
        ];
    }

    public function validateRow(array $row, ImportContext $context): array
    {
        $errors = [];

        $values = [
            'company_id' => $context->companyId,
            'customer_code' => $this->uniqueValue($row, 'customer_code', $context, 'customer code', 'customers', 'customer_code', $errors, required: false),
            'name' => $this->requireValue($row, 'name', $errors),
            'type' => $this->enum($row, 'type', $errors, CustomerType::class, CustomerType::Individual)?->value,
            'phone' => $this->value($row, 'phone'),
            'email' => $this->uniqueEmail($row, 'email', $context, 'customers', $errors),
            'address' => $this->value($row, 'address'),
            'city' => $this->value($row, 'city'),
            'province' => $this->value($row, 'province'),
            'country' => $this->value($row, 'country'),
            'postal_code' => $this->value($row, 'postal_code'),
            'tax_number' => $this->value($row, 'tax_number'),
            'credit_limit' => $this->decimal($row, 'credit_limit', $errors),
            'payment_terms' => $this->nonNegativeInteger($row, 'payment_terms', $errors),
            'notes' => $this->value($row, 'notes'),
            'is_active' => $this->flag($row, 'is_active', $errors),
        ];

        return $this->row(array_filter($values, static fn (mixed $value): bool => $value !== null), $errors);
    }

    public function commitRow(array $row, ImportContext $context): Customer
    {
        if (empty($row['customer_code'])) {
            $row['customer_code'] = $this->numbering->next('customer_code', $context->companyId, null, ['prefix' => 'CUST']);
        }

        return Customer::create($row);
    }
}
