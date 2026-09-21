<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['pcs', 'box', 'pack', 'carton', 'kg', 'gram', 'liter', 'ml']),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'type' => 'quantity',
            'precision' => 2,
            'is_base' => false,
            'status' => 'active',
        ];
    }
}
