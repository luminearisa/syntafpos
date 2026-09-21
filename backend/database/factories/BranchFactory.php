<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('BR-???')),
            'name' => fake()->word().' Outlet',
            'type' => 'outlet',
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'province' => fake()->state(),
            'country' => 'Indonesia',
            'postal_code' => fake()->postcode(),
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ];
    }
}
