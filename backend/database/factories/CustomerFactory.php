<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_code' => strtoupper(fake()->unique()->lexify('CUST-???')),
            'name' => fake()->name(),
            'type' => 'individual',
            'phone' => fake()->phoneNumber(),
            'email' => fake()->email(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'province' => fake()->state(),
            'credit_limit' => fake()->randomFloat(4, 0, 5000000),
            'payment_terms' => fake()->numberBetween(0, 30),
            'is_active' => true,
            'company_id' => null,
        ];
    }
}
