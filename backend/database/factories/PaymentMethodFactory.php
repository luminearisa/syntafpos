<?php

namespace Database\Factories;

use App\Enums\PaymentChannel;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        $channel = fake()->randomElement(PaymentChannel::cases());

        return [
            'code' => strtoupper(fake()->unique()->lexify('PM-???')),
            'name' => $channel->label(),
            'channel' => $channel->value,
            'provider' => null,
            'requires_reference' => false,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }

    /** The drawer tender, and the one a till opens on. */
    public function cash(): static
    {
        return $this->state(fn () => [
            'code' => 'CASH',
            'name' => 'Cash',
            'channel' => PaymentChannel::Cash->value,
            'is_default' => true,
        ]);
    }

    /** A tender a provider has to confirm before it may be called paid. */
    public function pendingCapture(string $provider = 'midtrans'): static
    {
        return $this->state(fn () => [
            'code' => 'QRIS',
            'name' => 'QRIS',
            'channel' => PaymentChannel::Qris->value,
            'provider' => $provider,
        ]);
    }
}
