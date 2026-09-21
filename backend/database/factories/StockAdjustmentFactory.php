<?php

namespace Database\Factories;

use App\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => strtoupper(fake()->unique()->lexify('ADJ-?????')),
            'adjustment_date' => fake()->date(),
            'adjustment_type' => 'increase',
            'reason' => 'other',
            'status' => 'posted',
            'posted_at' => now(),
        ];
    }
}
