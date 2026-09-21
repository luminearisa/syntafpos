<?php

namespace Database\Factories;

use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseLocation>
 */
class WarehouseLocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('LOC-???')),
            'name' => fake()->randomElement(['Zone A', 'Rack 1', 'Bin 12', 'Area B']),
            'type' => 'zone',
            'level' => 0,
            'status' => 'active',
        ];
    }
}
