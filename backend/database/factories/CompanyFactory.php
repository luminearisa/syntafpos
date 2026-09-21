<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' PT',
            'code' => strtoupper(fake()->unique()->lexify('?????')),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'province' => fake()->state(),
            'country' => 'Indonesia',
            'postal_code' => fake()->postcode(),
            'tax_number' => fake()->numerify('##.###.###.#-###.###'),
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'fiscal_year_start' => now()->startOfYear()->toDateString(),
            'status' => 'active',
        ];
    }
}
