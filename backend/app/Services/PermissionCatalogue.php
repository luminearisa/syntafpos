<?php

namespace App\Services;

/**
 * Central definition of the permission catalogue.
 *
 * Kept in code rather than the database so seeders, tests and policy guards
 * all agree on the exact permission names. Future modules append here.
 */
final class PermissionCatalogue
{
    /**
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'system' => [
                'system.manage',
                'audit.view',
            ],
            'companies' => ['companies.view', 'companies.create', 'companies.update', 'companies.delete'],
            'branches' => ['branches.view', 'branches.create', 'branches.update', 'branches.delete'],
            'warehouses' => ['warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete'],
            'registers' => ['registers.view', 'registers.create', 'registers.update', 'registers.delete'],
            'users' => ['users.view', 'users.create', 'users.update', 'users.delete'],
            'roles' => ['roles.view', 'roles.create', 'roles.update', 'roles.delete'],
            'settings' => ['settings.view', 'settings.update'],

            // Phase 2 — master data, inventory and purchasing.
            'products' => ['products.view', 'products.create', 'products.update', 'products.delete'],
            'categories' => ['categories.view', 'categories.create', 'categories.update', 'categories.delete'],
            'brands' => ['brands.view', 'brands.create', 'brands.update', 'brands.delete'],
            'attributes' => ['attributes.view', 'attributes.create', 'attributes.update', 'attributes.delete'],
            'units' => ['units.view', 'units.create', 'units.update', 'units.delete'],
            'customers' => ['customers.view', 'customers.create', 'customers.update', 'customers.delete'],
            'suppliers' => ['suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete'],
            'taxes' => ['taxes.view', 'taxes.create', 'taxes.update', 'taxes.delete'],
            'price_lists' => ['price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete'],
            'inventory' => [
                'inventory.view', 'inventory.adjust', 'inventory.opname',
                'inventory.transfer', 'inventory.approve',
            ],
            'purchases' => [
                'purchases.view', 'purchases.create', 'purchases.update',
                'purchases.approve', 'purchases.receive', 'purchases.cancel',
            ],
            'reports' => ['reports.inventory', 'reports.purchasing'],

            // Phase 3 — point of sale.
            'pos' => ['pos.view', 'pos.transact', 'pos.hold'],
            // A sale is a posted financial record, so it is authorised apart
            // from the draft cart that precedes it.
            'sales' => [
                'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
            ],
            // How a shop is allowed to be paid is configuration, and taking a
            // payment record away after the fact is not something the till
            // screen should be able to do, so delete sits apart from transact.
            'payment_methods' => [
                'payment_methods.view', 'payment_methods.create',
                'payment_methods.update', 'payment_methods.delete',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return collect(self::groups())->flatten()->values()->all();
    }

    /**
     * @return array<string, list<string>>
     */
    public static function roleMatrix(): array
    {
        return [
            // Super Admin and Owner are seeded as system roles and granted
            // everything; both bypass granular permission checks in policy.
            'super_admin' => self::all(),
            'owner' => self::all(),

            'admin' => collect(self::groups())
                ->except(['system'])
                ->flatten()
                ->values()
                ->all(),

            'manager' => collect(self::groups())
                ->only([
                    'branches', 'warehouses', 'registers', 'users', 'roles',
                    'products', 'categories', 'brands', 'units', 'customers', 'suppliers',
                    'sales',
                ])
                ->flatten()
                ->push('settings.view')
                ->push('inventory.view')
                ->push('purchases.view')
                ->push('payment_methods.view')
                ->push('pos.view', 'pos.transact', 'pos.hold')
                ->values()
                ->all(),

            'cashier' => [
                'registers.view',
                'settings.view',
                'products.view',
                'customers.view',
                'customers.create',
                // The till needs to know which methods its shop accepts, but not
                // to redefine them.
                'payment_methods.view',
                'pos.view', 'pos.transact', 'pos.hold',
                // A cashier raises and settles the ticket; taking one back is a
                // supervisor's call, so sales.cancel is deliberately absent.
                'sales.view', 'sales.create', 'sales.complete',
            ],

            'warehouse' => collect(self::groups())
                ->only(['products', 'categories', 'brands', 'units', 'suppliers'])
                ->flatten()
                ->push('warehouses.view', 'warehouses.create', 'warehouses.update')
                ->push('inventory.view', 'inventory.adjust', 'inventory.opname', 'inventory.transfer')
                ->push('purchases.view', 'purchases.create', 'purchases.receive')
                ->push('reports.inventory')
                ->values()
                ->all(),

            'finance' => collect(self::groups())
                ->only(['suppliers', 'taxes', 'sales', 'payment_methods'])
                ->flatten()
                ->push('settings.view')
                ->push('purchases.view', 'purchases.approve')
                ->push('reports.purchasing', 'reports.inventory')
                ->values()
                ->all(),

            'auditor' => collect(self::groups())
                ->only([
                    'companies', 'branches', 'warehouses', 'registers', 'users',
                    'products', 'categories', 'brands', 'units', 'customers', 'suppliers',
                ])
                ->map(fn (array $permissions) => collect($permissions)->filter(
                    fn (string $name) => str_ends_with($name, '.view')
                )->all())
                ->flatten()
                ->push('audit.view', 'settings.view')
                ->push('inventory.view', 'purchases.view', 'sales.view')
                ->push('payment_methods.view')
                ->push('reports.inventory', 'reports.purchasing')
                ->values()
                ->all(),
        ];
    }

    public static function displayNames(): array
    {
        return [

            'attributes.create' => 'Create attributes',
            'attributes.delete' => 'Delete attributes',
            'attributes.update' => 'Update attributes',
            'attributes.view' => 'View attributes',
            'pos.hold' => 'Hold and recall carts',
            'pos.transact' => 'Work a point-of-sale cart',
            'pos.view' => 'View the point of sale',
            'audit.view' => 'View system',
            'branches.create' => 'Create branches',
            'branches.delete' => 'Delete branches',
            'branches.update' => 'Update branches',
            'branches.view' => 'View branches',
            'brands.create' => 'Create brands',
            'brands.delete' => 'Delete brands',
            'brands.update' => 'Update brands',
            'brands.view' => 'View brands',
            'categories.create' => 'Create categories',
            'categories.delete' => 'Delete categories',
            'categories.update' => 'Update categories',
            'categories.view' => 'View categories',
            'companies.create' => 'Create companies',
            'companies.delete' => 'Delete companies',
            'companies.update' => 'Update companies',
            'companies.view' => 'View companies',
            'customers.create' => 'Create customers',
            'customers.delete' => 'Delete customers',
            'customers.update' => 'Update customers',
            'customers.view' => 'View customers',
            'inventory.adjust' => 'Adjust stock',
            'inventory.approve' => 'Approve inventory',
            'inventory.opname' => 'Run stock opname',
            'inventory.transfer' => 'Transfer stock',
            'inventory.view' => 'View inventory',
            'price_lists.create' => 'Create price lists',
            'price_lists.delete' => 'Delete price lists',
            'price_lists.update' => 'Update price lists',
            'price_lists.view' => 'View price lists',
            'products.create' => 'Create products',
            'products.delete' => 'Delete products',
            'products.update' => 'Update products',
            'products.view' => 'View products',
            'purchases.approve' => 'Approve purchases',
            'purchases.cancel' => 'Cancel purchases',
            'purchases.create' => 'Create purchases',
            'purchases.receive' => 'Receive goods purchases',
            'purchases.update' => 'Update purchases',
            'purchases.view' => 'View purchases',
            'sales.cancel' => 'Cancel sales',
            'sales.complete' => 'Complete sales',
            'sales.create' => 'Create sales',
            'sales.view' => 'View sales',
            'payment_methods.create' => 'Create payment methods',
            'payment_methods.delete' => 'Delete payment methods',
            'payment_methods.update' => 'Update payment methods',
            'payment_methods.view' => 'View payment methods',
            'registers.create' => 'Create registers',
            'registers.delete' => 'Delete registers',
            'registers.update' => 'Update registers',
            'registers.view' => 'View registers',
            'reports.inventory' => 'View inventory reports',
            'reports.purchasing' => 'View purchasing reports',
            'roles.create' => 'Create roles',
            'roles.delete' => 'Delete roles',
            'roles.update' => 'Update roles',
            'roles.view' => 'View roles',
            'settings.update' => 'Update settings',
            'settings.view' => 'View settings',
            'suppliers.create' => 'Create suppliers',
            'suppliers.delete' => 'Delete suppliers',
            'suppliers.update' => 'Update suppliers',
            'suppliers.view' => 'View suppliers',
            'system.manage' => 'Manage system',
            'taxes.create' => 'Create taxes',
            'taxes.delete' => 'Delete taxes',
            'taxes.update' => 'Update taxes',
            'taxes.view' => 'View taxes',
            'units.create' => 'Create units',
            'units.delete' => 'Delete units',
            'units.update' => 'Update units',
            'units.view' => 'View units',
            'users.create' => 'Create users',
            'users.delete' => 'Delete users',
            'users.update' => 'Update users',
            'users.view' => 'View users',
            'warehouses.create' => 'Create warehouses',
            'warehouses.delete' => 'Delete warehouses',
            'warehouses.update' => 'Update warehouses',
            'warehouses.view' => 'View warehouses',
        ];
    }
}
