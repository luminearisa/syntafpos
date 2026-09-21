<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('WH-???')),
            'name' => fake()->word().' Warehouse',
            'description' => fake()->sentence(),
            'type' => 'main',
            'status' => 'active',
        ];
    }
}
