<?php

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('TAX-?')),
            'name' => fake()->randomElement(['PPN', 'PPH', 'Service Charge']),
            'rate' => fake()->randomFloat(4, 0, 20),
            'type' => 'exclusive',
            'status' => 'active',
        ];
    }
}
