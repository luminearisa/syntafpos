<?php

namespace Database\Factories;

use App\Models\WarehouseTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseTransfer>
 */
class WarehouseTransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => strtoupper(fake()->unique()->lexify('TRF-?????')),
            'transfer_date' => fake()->date(),
            'status' => 'completed',
            'shipped_at' => now(),
            'received_at' => now(),
        ];
    }
}
