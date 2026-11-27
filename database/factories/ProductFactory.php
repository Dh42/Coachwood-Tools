<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'asin' => 'B'.fake()->unique()->numerify('#########'),
            'sku' => fake()->unique()->bothify('SKU-####'),
            'title' => fake()->words(4, true),
            'type' => 'DRINK_FLAVORED',
            'list_price' => fake()->randomFloat(2, 10, 100),
            'cost' => fake()->randomFloat(2, 5, 50),
            'active' => true,
        ];
    }
}
