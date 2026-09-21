<?php

namespace Database\Factories;

use App\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Price List',
            'currency' => 'IDR',
            'start_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'is_default' => fake()->boolean(20),
            'status' => 'active',
        ];
    }
}
