<?php

namespace Database\Factories;

use App\Enums\RegisterSessionStatus;
use App\Models\RegisterSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegisterSession>
 */
class RegisterSessionFactory extends Factory
{
    protected $model = RegisterSession::class;

    public function definition(): array
    {
        $opened = fake()->dateTimeBetween('-1 day', 'now');

        return [
            'number' => 'SHIFT-'.fake()->unique()->numerify('####'),
            'status' => RegisterSessionStatus::Open->value,
            'opening_balance' => number_format(fake()->numberBetween(1, 20) * 100000, 4, '.', ''),
            'opened_at' => $opened,
            // register_open_key is set by the engine, not here: a factory row that
            // claimed a register's open slot behind the service's back would let a
            // test pass while production's unique index refused it.
            'register_open_key' => null,
        ];
    }

    /** A counted, shut shift: everything the closing report needs to exist. */
    public function closed(array $attributes = []): static
    {
        return $this->state(fn () => array_merge([
            'status' => RegisterSessionStatus::Closed->value,
            'register_open_key' => null,
            'closed_at' => now()->addHours(8),
            'closing_balance' => '0.0000',
            'actual_balance' => '0.0000',
            'variance' => '0.0000',
            'variance_threshold' => '0.0000',
        ], $attributes));
    }
}
