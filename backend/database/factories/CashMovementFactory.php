<?php

namespace Database\Factories;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(CashMovementType::cases())->value,
            'amount' => number_format(fake()->numberBetween(1, 50) * 10000, 4, '.', ''),
            'currency' => 'IDR',
            'reason' => rtrim(fake()->sentence(), '.'),
            'reference' => strtoupper(fake()->optional(0.6)->bothify('REF-####??')),
            'occurred_at' => now(),
        ];
    }

    /** A movement that takes money out of the drawer. */
    public function outflow(): static
    {
        return $this->state(fn () => ['type' => CashMovementType::Expense->value]);
    }
}
