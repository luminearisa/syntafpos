<?php

namespace Database\Factories;

use App\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseReturn>
 */
class PurchaseReturnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => strtoupper(fake()->unique()->lexify('RET-?????')),
            'return_date' => fake()->date(),
            'status' => 'posted',
            'posted_at' => now(),
            'total_amount' => '0',
        ];
    }
}
