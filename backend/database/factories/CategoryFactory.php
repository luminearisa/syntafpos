<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('CAT-???')),
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'level' => 0,
            'sort_order' => fake()->numberBetween(0, 100),
            'status' => 'active',
        ];
    }
}
