<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => strtoupper(fake()->unique()->lexify('GR-?????')),
            'receipt_date' => fake()->date(),
            'status' => 'posted',
            'posted_at' => now(),
        ];
    }
}
