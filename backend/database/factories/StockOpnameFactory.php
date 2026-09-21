<?php

namespace Database\Factories;

use App\Models\StockOpname;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockOpname>
 */
class StockOpnameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => strtoupper(fake()->unique()->lexify('OPN-?????')),
            'opname_date' => fake()->date(),
            'status' => 'posted',
            'counted_at' => now(),
            'posted_at' => now(),
        ];
    }
}
