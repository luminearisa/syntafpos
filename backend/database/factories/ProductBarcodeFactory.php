<?php

namespace Database\Factories;

use App\Enums\BarcodeType;
use App\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBarcode>
 */
class ProductBarcodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->ean13(),
            'type' => fake()->randomElement(array_column(BarcodeType::cases(), 'value')),
        ];
    }
}
