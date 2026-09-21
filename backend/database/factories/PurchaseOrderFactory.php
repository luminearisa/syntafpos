<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => strtoupper(fake()->unique()->lexify('PO-?????')),
            'order_date' => fake()->date(),
            'expected_date' => fake()->dateTimeBetween('+1 day', '+30 days')->format('Y-m-d'),
            'payment_terms' => fake()->numberBetween(0, 60),
            'currency' => 'IDR',
            'status' => 'draft',
            'subtotal' => '0',
            'grand_total' => '0',
        ];
    }
}
