<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->lexify('SKU-?????')),
            'barcode' => fake()->unique()->ean13(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'product_type' => 'simple',
            'track_inventory' => true,
            'allow_negative_stock' => false,
            'is_sellable' => true,
            'is_purchasable' => true,
            'is_active' => true,
            'cost_price' => fake()->randomFloat(4, 100, 100000),
            'selling_price' => fake()->randomFloat(4, 200, 150000),
            'weight' => fake()->randomFloat(4, 0, 50),
            'minimum_stock' => fake()->numberBetween(0, 20),
            'reorder_point' => fake()->numberBetween(0, 15),
        ];
    }
}
