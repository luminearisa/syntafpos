<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Register;
use App\Models\Warehouse;
use Illuminate\Http\Request;

/**
 * Holds the business context resolved on each request.
 *
 * Cashier scope is intentionally narrow: a user may have access to several
 * companies but a single transaction always belongs to one company, so the
 * effective context is resolved once per request and reused everywhere.
 */
class BusinessContext
{
    protected ?Company $company = null;

    protected ?Branch $branch = null;

    protected ?Warehouse $warehouse = null;

    protected ?Register $register = null;

    public function setCompany(?Company $company): void
    {
        $this->company = $company;
        $this->branch = null;
        $this->warehouse = null;
        $this->register = null;
    }

    public function setBranch(?Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function setWarehouse(?Warehouse $warehouse): void
    {
        $this->warehouse = $warehouse;
    }

    public function setRegister(?Register $register): void
    {
        $this->register = $register;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function warehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    public function register(): ?Register
    {
        return $this->register;
    }

    public function companyId(): ?int
    {
        return $this->company?->id;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function warehouseId(): ?int
    {
        return $this->warehouse?->id;
    }

    public function registerId(): ?int
    {
        return $this->register?->id;
    }

    public function fromRequest(Request $request): void
    {
        $user = $request->user();

        $company = $this->resolveEntity(
            Company::query(),
            $request->header('X-Company-Id'),
            fn (?int $id) => $user?->companies()->where('companies.id', $id)->first()
        );

        $this->setCompany($company);

        if (! $company) {
            return;
        }

        $this->setBranch($this->resolveEntity(
            Branch::query()->where('company_id', $company->id),
            $request->header('X-Branch-Id'),
            fn (?int $id) => $user?->branches()
                ->where('branches.company_id', $company->id)
                ->where('branches.id', $id)
                ->first()
        ));

        $this->setWarehouse($this->resolveEntity(
            Warehouse::query()->where('company_id', $company->id),
            $request->header('X-Warehouse-Id'),
            fn (?int $id) => $user?->warehouses()
                ->where('warehouses.company_id', $company->id)
                ->where('warehouses.id', $id)
                ->first()
        ));

        $this->setRegister($this->resolveEntity(
            Register::query()->where('company_id', $company->id),
            $request->header('X-Register-Id'),
            fn (?int $id) => $user?->registers()
                ->where('registers.company_id', $company->id)
                ->where('registers.id', $id)
                ->first()
        ));
    }

    /**
     * Resolve an entity by id, preferring the user's accessible subset.
     *
     * @param  callable(?int): ?Model  $withinAccess
     */
    private function resolveEntity(mixed $query, mixed $id, callable $withinAccess): mixed
    {
        if (! $id) {
            return null;
        }

        return $withinAccess($id) ?? $query->find($id);
    }
}
