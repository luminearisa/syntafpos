<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use Tests\TestCase;

class PartyTest extends TestCase
{
    public function test_customer_crud_and_code_unique_per_company(): void
    {
        $user = $this->authenticatedUser(['customers.create', 'customers.update', 'customers.delete', 'customers.view']);
        $existing = Customer::factory()->for($this->company)->create();

        // The code is unique inside one company.
        $this->postJson('/api/v1/customers', [
            'company_id' => $this->company->id,
            'customer_code' => $existing->customer_code,
            'name' => 'Duplicate',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_code');

        $created = $this->postJson('/api/v1/customers', [
            'company_id' => $this->company->id,
            'customer_code' => 'CUST-0001',
            'name' => 'John Doe',
            'type' => 'individual',
            'phone' => '0812000111222',
            'email' => 'john@example.com',
            'credit_limit' => '1000000.0000',
            'payment_terms' => 14,
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.customer_code', 'CUST-0001')
            ->assertJsonPath('data.type', 'individual')
            ->assertJsonPath('data.credit_limit', '1000000.0000')
            ->assertJsonPath('data.payment_terms', 14);

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.create']);
        $id = $created->json('data.id');

        // A malformed email is rejected; a good one is stored.
        $this->putJson("/api/v1/customers/{$id}", ['email' => 'not-an-email'], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->putJson("/api/v1/customers/{$id}", ['name' => 'Jane Doe', 'credit_limit' => '2500000.5000'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.credit_limit', '2500000.5000');

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.update']);

        $this->getJson("/api/v1/customers/{$id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.customer_code', 'CUST-0001');

        // Search matches the name and skips the other customer.
        $this->getJson('/api/v1/customers?search=Jane', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/v1/customers/{$id}", [], $this->authHeaders($user))->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.delete']);
    }

    public function test_customer_group_and_price_list_must_belong_to_the_same_company(): void
    {
        $user = $this->authenticatedUser(['customers.create', 'customers.view']);
        $foreignGroup = CustomerGroup::create(['company_id' => Company::factory()->create()->id, 'name' => 'Foreign']);

        $this->postJson('/api/v1/customers', [
            'company_id' => $this->company->id,
            'customer_code' => 'CUST-GROUP',
            'name' => 'Grouped Customer',
            'customer_group_id' => $foreignGroup->id,
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_group_id');
    }

    public function test_customer_code_is_free_under_a_second_company(): void
    {
        $user = $this->authenticatedUser(['customers.create', 'customers.view']);
        $customer = Customer::factory()->for($this->company)->create();

        $otherCompany = Company::factory()->create();
        $user->companies()->attach($otherCompany->id);
        $otherRole = Role::create([
            'company_id' => $otherCompany->id,
            'name' => 'customers_other',
            'display_name' => 'Customers Other',
        ]);
        $otherRole->permissions()->sync(Permission::whereIn('name', ['customers.create'])->pluck('id')->all());
        $user->roles()->attach($otherRole->id);
        $user->clearPermissionCache($otherCompany->id);

        $this->postJson('/api/v1/customers', [
            'company_id' => $otherCompany->id,
            'customer_code' => $customer->customer_code,
            'name' => 'Other Co Customer',
        ], $this->authHeaders($user, ['company_id' => $otherCompany->id]))->assertCreated();
    }

    public function test_customer_group_crud_uses_customer_permissions(): void
    {
        // Reading rides customers.view, mutating rides customers.update.
        $user = $this->authenticatedUser(['customers.view', 'customers.update']);

        $created = $this->postJson('/api/v1/customer-groups', [
            'company_id' => $this->company->id,
            'name' => 'Retail',
            'description' => 'Walk-in retail customers',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Retail');

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer_group.create']);
        $id = $created->json('data.id');

        $this->putJson("/api/v1/customer-groups/{$id}", ['name' => 'Wholesale'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.name', 'Wholesale');

        $this->getJson('/api/v1/customer-groups', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/customer-groups/{$id}", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.customers_count', 0);

        $this->deleteJson("/api/v1/customer-groups/{$id}", [], $this->authHeaders($user))->assertOk();
        $this->assertSoftDeleted('customer_groups', ['id' => $id]);
    }

    public function test_customer_group_name_is_unique_per_company(): void
    {
        $user = $this->authenticatedUser(['customers.update', 'customers.view']);
        $group = CustomerGroup::create(['company_id' => $this->company->id, 'name' => 'VIP']);

        $this->postJson('/api/v1/customer-groups', [
            'company_id' => $this->company->id,
            'name' => 'VIP',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // The same name stays free under another company.
        $otherCompany = Company::factory()->create();
        $user->companies()->attach($otherCompany->id);
        $otherRole = Role::create([
            'company_id' => $otherCompany->id,
            'name' => 'groups_other',
            'display_name' => 'Groups Other',
        ]);
        $otherRole->permissions()->sync(Permission::whereIn('name', ['customers.update'])->pluck('id')->all());
        $user->roles()->attach($otherRole->id);
        $user->clearPermissionCache($otherCompany->id);

        $this->postJson('/api/v1/customer-groups', [
            'company_id' => $otherCompany->id,
            'name' => $group->name,
        ], $this->authHeaders($user, ['company_id' => $otherCompany->id]))->assertCreated();
    }

    public function test_supplier_crud_and_code_unique_per_company(): void
    {
        $user = $this->authenticatedUser(['suppliers.create', 'suppliers.update', 'suppliers.delete', 'suppliers.view']);
        $existing = Supplier::factory()->for($this->company)->create();

        $this->postJson('/api/v1/suppliers', [
            'company_id' => $this->company->id,
            'supplier_code' => $existing->supplier_code,
            'name' => 'Duplicate',
        ], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_code');

        $created = $this->postJson('/api/v1/suppliers', [
            'company_id' => $this->company->id,
            'supplier_code' => 'SUP-0001',
            'name' => 'Acme Supplies',
            'company_name' => 'Acme Supplies Ltd',
            'contact_person' => 'Jane Smith',
            'phone' => '0215550111',
            'email' => 'sales@acme.example',
            'payment_terms' => 30,
            'credit_limit' => '5000000.0000',
            'bank_name' => 'Bank Example',
            'bank_account' => '1234567890',
            'bank_account_name' => 'Acme Supplies Ltd',
            'status' => 'active',
        ], $this->authHeaders($user))
            ->assertCreated()
            ->assertJsonPath('data.supplier_code', 'SUP-0001')
            ->assertJsonPath('data.bank_account', '1234567890')
            ->assertJsonPath('data.credit_limit', '5000000.0000');

        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier.create']);
        $id = $created->json('data.id');

        $this->putJson("/api/v1/suppliers/{$id}", ['contact_person' => 'John Smith'], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.contact_person', 'John Smith');

        $this->getJson('/api/v1/suppliers?search=Acme', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/suppliers?status=inactive', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->deleteJson("/api/v1/suppliers/{$id}", [], $this->authHeaders($user))->assertOk();
        $this->assertSoftDeleted('suppliers', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplier.delete']);
    }

    public function test_supplier_summary_is_zero_when_there_are_no_transactions(): void
    {
        $user = $this->authenticatedUser(['suppliers.view']);
        $supplier = Supplier::factory()->for($this->company)->create();

        // No document of any kind exists, so every figure is zero, not null.
        $this->getJson("/api/v1/suppliers/{$supplier->id}/summary", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.total_purchase', '0.0000')
            ->assertJsonPath('data.total_return', '0.0000')
            ->assertJsonPath('data.paid', '0.0000')
            ->assertJsonPath('data.outstanding', '0.0000')
            ->assertJsonPath('data.purchase_count', 0)
            ->assertJsonPath('data.last_purchase_date', null)
            ->assertJsonPath('data.due_date', null);
    }

    public function test_supplier_summary_aggregates_real_receipts_and_returns(): void
    {
        $user = $this->authenticatedUser(['suppliers.view']);
        $supplier = Supplier::factory()->for($this->company)->create(['payment_terms' => 30]);
        $product = Product::factory()->for($this->company)->create();
        $unit = Unit::factory()->for($this->company)->create();

        $receipt = GoodsReceipt::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(['receipt_date' => '2026-09-01']);

        // The receipt has no total column: its value is derived from its lines.
        GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity_received' => '10',
            'unit_cost' => '500.0000',
        ]);
        GoodsReceiptItem::create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity_received' => '2.5',
            'unit_cost' => '1000.0000',
        ]);

        PurchaseReturn::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(['total_amount' => '1500.0000']);

        $this->getJson("/api/v1/suppliers/{$supplier->id}/summary", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.total_purchase', '7500.0000')
            ->assertJsonPath('data.total_return', '1500.0000')
            ->assertJsonPath('data.paid', '0.0000')
            ->assertJsonPath('data.outstanding', '6000.0000')
            ->assertJsonPath('data.purchase_count', 1)
            ->assertJsonPath('data.last_purchase_date', '2026-09-01')
            ->assertJsonPath('data.due_date', '2026-10-01');
    }

    public function test_supplier_summary_ignores_draft_documents(): void
    {
        $user = $this->authenticatedUser(['suppliers.view']);
        $supplier = Supplier::factory()->for($this->company)->create();
        $product = Product::factory()->for($this->company)->create();
        $unit = Unit::factory()->for($this->company)->create();

        $draft = GoodsReceipt::factory()
            ->for($this->company)
            ->for($this->warehouse)
            ->for($supplier)
            ->create(['status' => 'draft', 'posted_at' => null]);

        GoodsReceiptItem::create([
            'goods_receipt_id' => $draft->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity_received' => '10',
            'unit_cost' => '500.0000',
        ]);

        $this->getJson("/api/v1/suppliers/{$supplier->id}/summary", $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('data.total_purchase', '0.0000')
            ->assertJsonPath('data.purchase_count', 0);
    }

    public function test_party_modules_are_forbidden_without_permission(): void
    {
        // One customer permission only, so suppliers must be denied outright.
        $user = $this->authenticatedUser(['customers.view']);

        $this->getJson('/api/v1/suppliers', $this->authHeaders($user))->assertStatus(403);
        $this->postJson('/api/v1/customers', [
            'company_id' => $this->company->id,
            'customer_code' => 'NOPE',
            'name' => 'Denied',
        ], $this->authHeaders($user))->assertStatus(403);

        // Reading the auxiliary groups rides customers.view, mutating needs
        // customers.update, which this user lacks.
        $this->getJson('/api/v1/customer-groups', $this->authHeaders($user))->assertOk();

        $this->postJson('/api/v1/customer-groups', [
            'company_id' => $this->company->id,
            'name' => 'Denied',
        ], $this->authHeaders($user))->assertStatus(403);

        // The granted permission still works.
        $this->getJson('/api/v1/customers', $this->authHeaders($user))->assertOk();
    }

    public function test_parties_are_isolated_between_companies(): void
    {
        $user = $this->authenticatedUser(['customers.view', 'suppliers.view']);
        $other = Company::factory()->create();

        $foreignCustomer = Customer::factory()->for($other)->create();
        $foreignSupplier = Supplier::factory()->for($other)->create();

        $this->getJson("/api/v1/customers/{$foreignCustomer->id}", $this->authHeaders($user))->assertStatus(403);
        $this->getJson("/api/v1/suppliers/{$foreignSupplier->id}", $this->authHeaders($user))->assertStatus(403);
        $this->getJson("/api/v1/suppliers/{$foreignSupplier->id}/summary", $this->authHeaders($user))->assertStatus(403);

        $this->getJson('/api/v1/customers', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/suppliers', $this->authHeaders($user))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
