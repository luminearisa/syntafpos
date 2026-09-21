<?php

namespace Database\Factories;

use App\Enums\PriceType;
use App\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'price_type' => fake()->randomElement(array_column(PriceType::cases(), 'value')),
            'price' => fake()->randomFloat(4, 100, 100000),
            'minimum_price' => fake()->optional(0.3)->randomFloat(4, 50, 90000),
        ];
    }
}
