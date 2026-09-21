<?php

namespace Database\Factories;

use App\Models\AttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttributeValue>
 */
class AttributeValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }
}
