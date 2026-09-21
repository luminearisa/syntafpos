<?php

namespace Database\Factories;

use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => strtoupper(fake()->unique()->lexify('PR-?????')),
            'request_date' => fake()->date(),
            'required_date' => fake()->dateTimeBetween('+1 day', '+30 days')->format('Y-m-d'),
            'status' => 'draft',
        ];
    }
}
