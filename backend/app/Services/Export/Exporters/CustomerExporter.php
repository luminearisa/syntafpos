<?php

namespace App\Services\Export\Exporters;

use App\Models\Customer;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class CustomerExporter extends Exporter
{
    public function entity(): string
    {
        return 'customers';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    public function columns(): array
    {
        return [
            'customer_code', 'name', 'type', 'phone', 'email', 'address', 'city',
            'province', 'country', 'postal_code', 'tax_number', 'credit_limit',
            'payment_terms', 'is_active', 'created_at',
        ];
    }

    public function filters(): array
    {
        return ['search', 'is_active'];
    }

    public function query(Request $request, int $companyId): Builder
    {
        return Customer::query()
            ->visibleTo($request->user())
            ->where('company_id', $companyId)
            ->when($request->search, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')));
    }

    public function map(Model $record, Request $request): array
    {
        /** @var Customer $record */

        return [
            $record->customer_code,
            $record->name,
            $record->type->value,
            $record->phone,
            $record->email,
            $record->address,
            $record->city,
            $record->province,
            $record->country,
            $record->postal_code,
            $record->tax_number,
            $record->credit_limit,
            $record->payment_terms,
            $record->is_active ? '1' : '0',
            $record->created_at?->toDateTimeString(),
        ];
    }
}
