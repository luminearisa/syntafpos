<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PermissionCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected ?Company $company = null;

    protected ?Branch $branch = null;

    protected ?Warehouse $warehouse = null;

    protected ?Register $register = null;

    protected function seedPermissions(): void
    {
        foreach (PermissionCatalogue::groups() as $group => $permissions) {
            foreach ($permissions as $name) {
                Permission::firstOrCreate(
                    ['name' => $name],
                    ['display_name' => $name, 'group' => $group]
                );
            }
        }
    }

    /**
     * Build the demo business tree and return an authenticated user.
     *
     * @param  list<string>  $permissions
     */
    protected function authenticatedUser(array $permissions = [], ?string $roleName = null): User
    {
        $this->seedPermissions();

        $company = Company::factory()->create();
        $branch = Branch::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->for($branch)->create();
        $register = Register::factory()->for($company)->for($branch)->for($warehouse)->create();

        $this->company = $company;
        $this->branch = $branch;
        $this->warehouse = $warehouse;
        $this->register = $register;

        $user = User::factory()->create();
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);
        $user->warehouses()->attach($warehouse->id);
        $user->registers()->attach($register->id);

        $role = Role::create([
            'company_id' => $company->id,
            'name' => $roleName ?? 'test_role',
            'display_name' => 'Test Role',
        ]);

        // An empty permission list means none; only fall back when unspecified.
        $names = $permissions ?: (func_num_args() === 1 ? [] : ['companies.view']);
        $role->permissions()->sync(Permission::whereIn('name', $names)->pluck('id')->all());
        $user->roles()->attach($role->id);
        $user->clearPermissionCache($company->id);

        return $user;
    }

    /**
     * Auth headers plus the business-context headers consumed by the app.
     *
     * The guard is dropped first: it caches whoever resolved first for the life
     * of the test method, so a second user's headers would otherwise still be
     * answered as the first one.
     *
     * @return array<string, string>
     */
    protected function authHeaders(User $user, array $context = []): array
    {
        $this->app['auth']->forgetGuards();

        return array_filter([
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'X-Company-Id' => (string) ($context['company_id'] ?? $this->company?->id ?? ''),
            'X-Branch-Id' => isset($context['branch_id']) ? (string) $context['branch_id'] : null,
            'X-Warehouse-Id' => isset($context['warehouse_id']) ? (string) $context['warehouse_id'] : null,
            'X-Register-Id' => isset($context['register_id']) ? (string) $context['register_id'] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
