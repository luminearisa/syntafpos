<?php

namespace Database\Factories;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Size', 'Color', 'Flavor', 'Material']),
            'display_type' => 'select',
            'sort_order' => fake()->numberBetween(0, 50),
            'status' => 'active',
        ];
    }
}
