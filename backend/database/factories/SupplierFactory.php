<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_code' => strtoupper(fake()->unique()->lexify('SUP-???')),
            'name' => fake()->company(),
            'company_name' => fake()->company(),
            'contact_person' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'province' => fake()->state(),
            'payment_terms' => fake()->numberBetween(0, 60),
            'credit_limit' => fake()->randomFloat(4, 0, 10000000),
            'status' => 'active',
            'company_id' => null,
        ];
    }
}
