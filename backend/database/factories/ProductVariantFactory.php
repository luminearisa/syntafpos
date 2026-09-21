<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->lexify('VAR-?????')),
            'barcode' => fake()->unique()->ean13(),
            'name' => fake()->colorName().' / '.fake()->randomElement(['S', 'M', 'L']),
            'cost_price' => fake()->randomFloat(4, 100, 50000),
            'selling_price' => fake()->randomFloat(4, 200, 75000),
            'weight' => fake()->randomFloat(4, 0, 20),
            'is_default' => false,
            'is_active' => true,
            'company_id' => null,
            'product_id' => null,
        ];
    }
}
