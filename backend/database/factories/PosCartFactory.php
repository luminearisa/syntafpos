<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\PosCart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosCart>
 */
class PosCartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'status' => CartStatus::Active->value,
            'currency' => 'IDR',
        ];
    }

    /**
     * A parked draft. The recall code is numbered by PosCartService::hold(), so
     * tests that build one directly pass their own number.
     */
    public function held(): static
    {
        return $this->state(fn () => [
            'status' => CartStatus::Held->value,
            'held_at' => now(),
        ]);
    }
}
