<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissions();

        $company = Company::create([
            'name' => 'Demo Business',
            'legal_name' => 'Demo Business, PT',
            'code' => 'DEMO',
            'email' => 'demo@example.com',
            'phone' => '+6281234567890',
            'address' => 'Jl. Demo Utama No. 1',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'country' => 'Indonesia',
            'postal_code' => '10110',
            'tax_number' => '01.234.567.8-901.000',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'fiscal_year_start' => now()->startOfYear()->toDateString(),
            'status' => 'active',
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'code' => 'HO-01',
            'name' => 'Main Outlet',
            'type' => 'outlet',
            'address' => 'Jl. Demo Utama No. 1',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'country' => 'Indonesia',
            'status' => 'active',
        ]);

        $warehouse = Warehouse::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'WH-MAIN',
            'name' => 'Main Warehouse',
            'type' => 'main',
            'status' => 'active',
        ]);

        $register = Register::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id,
            'code' => 'REG-01',
            'name' => 'Register 01',
            'status' => 'active',
        ]);

        $this->seedRoles($company->id);

        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@example.com',
            'password' => 'password',
            'phone' => '+6281234567000',
            'status' => 'active',
        ]);

        $admin->companies()->attach($company->id);
        $admin->branches()->attach($branch->id);
        $admin->warehouses()->attach($warehouse->id);
        $admin->registers()->attach($register->id);
        $admin->roles()->attach(Role::whereNull('company_id')->where('name', 'super_admin')->firstOrFail()->id);

        $this->seedSettings($company->id);
    }

    private function seedPermissions(): void
    {
        $names = PermissionCatalogue::displayNames();

        foreach (PermissionCatalogue::groups() as $group => $permissions) {
            foreach ($permissions as $name) {
                Permission::firstOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $names[$name] ?? $name,
                        'group' => $group,
                    ]
                );
            }
        }
    }

    private function seedRoles(int $companyId): void
    {
        foreach (PermissionCatalogue::roleMatrix() as $name => $permissions) {
            $isSystem = in_array($name, ['super_admin', 'owner'], true);

            $role = Role::firstOrCreate(
                ['company_id' => $isSystem ? null : $companyId, 'name' => $name],
                [
                    'display_name' => ucwords(str_replace('_', ' ', $name)),
                    'description' => $isSystem
                        ? 'System role, granted automatically. Cannot be deleted.'
                        : 'Seeded role for the demo company.',
                    'is_system' => $isSystem,
                ]
            );

            $role->permissions()->sync(
                Permission::whereIn('name', $permissions)->pluck('id')->all()
            );
        }
    }

    private function seedSettings(int $companyId): void
    {
        $defaults = [
            'company.name' => ['value' => 'Demo Business', 'type' => 'string', 'group' => 'company'],
            'company.currency' => ['value' => 'IDR', 'type' => 'string', 'group' => 'company'],
            'company.timezone' => ['value' => 'Asia/Jakarta', 'type' => 'string', 'group' => 'company'],
            'company.currency_symbol' => ['value' => 'Rp', 'type' => 'string', 'group' => 'company'],
            'company.currency_decimals' => ['value' => '0', 'type' => 'integer', 'group' => 'company'],
            'company.currency_thousand_separator' => ['value' => '.', 'type' => 'string', 'group' => 'company'],
            'company.currency_decimal_separator' => ['value' => ',', 'type' => 'string', 'group' => 'company'],

            'pos.receipt_width' => ['value' => '80', 'type' => 'integer', 'group' => 'pos'],
            'pos.auto_print' => ['value' => '1', 'type' => 'boolean', 'group' => 'pos'],
            'pos.allow_negative_stock' => ['value' => '0', 'type' => 'boolean', 'group' => 'pos'],

            // Off, and zero: a shop that has never heard of shift reconciliation
            // must still be able to sell, and a tolerance of nothing is the honest
            // default for a setting nobody has chosen yet.
            'registers.require_open_shift' => ['value' => '0', 'type' => 'boolean', 'group' => 'registers'],
            'registers.variance_threshold' => ['value' => '0', 'type' => 'decimal', 'group' => 'registers'],

            'inventory.enabled' => ['value' => '1', 'type' => 'boolean', 'group' => 'inventory'],
            'inventory.valuation_method' => ['value' => 'average', 'type' => 'string', 'group' => 'inventory'],

            'accounting.enabled' => ['value' => '1', 'type' => 'boolean', 'group' => 'accounting'],
            'accounting.fiscal_year' => ['value' => 'yearly', 'type' => 'string', 'group' => 'accounting'],
            'accounting.standard' => ['value' => 'sak_emkm', 'type' => 'string', 'group' => 'accounting'],

            'tax.enabled' => ['value' => '1', 'type' => 'boolean', 'group' => 'tax'],
            'tax.default_rate' => ['value' => '11', 'type' => 'decimal', 'group' => 'tax'],

            'payment.enabled' => ['value' => '1', 'type' => 'boolean', 'group' => 'payment'],
        ];

        foreach ($defaults as $key => $config) {
            DB::table('settings')->updateOrInsert(
                ['company_id' => $companyId, 'key' => $key],
                array_merge($config, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
