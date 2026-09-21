<?php

namespace App\Services\Export\Exporters;

use App\Models\Supplier;
use App\Services\Export\Exporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class SupplierExporter extends Exporter
{
    public function entity(): string
    {
        return 'suppliers';
    }

    public function permission(): string
    {
        return 'suppliers.view';
    }

    public function columns(): array
    {
        return [
            'supplier_code', 'name', 'company_name', 'contact_person', 'phone',
            'email', 'address', 'city', 'province', 'country', 'postal_code',
            'tax_number', 'payment_terms', 'credit_limit', 'status', 'created_at',
        ];
    }

    public function filters(): array
    {
        return ['search', 'status'];
    }

    public function query(Request $request, int $companyId): Builder
    {
        return Supplier::query()
            ->visibleTo($request->user())
            ->where('company_id', $companyId)
            ->when($request->search, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('supplier_code', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->status, fn (Builder $query, string $status) => $query->where('status', $status));
    }

    public function map(Model $record, Request $request): array
    {
        /** @var Supplier $record */

        return [
            $record->supplier_code,
            $record->name,
            $record->company_name,
            $record->contact_person,
            $record->phone,
            $record->email,
            $record->address,
            $record->city,
            $record->province,
            $record->country,
            $record->postal_code,
            $record->tax_number,
            $record->payment_terms,
            $record->credit_limit,
            $record->status,
            $record->created_at?->toDateTimeString(),
        ];
    }
}
