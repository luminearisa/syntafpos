<?php

namespace Database\Factories;

use App\Models\Register;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Register>
 */
class RegisterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('REG-??')),
            'name' => 'Register '.fake()->numberBetween(1, 99),
            'description' => fake()->sentence(),
            'status' => 'active',
        ];
    }
}
