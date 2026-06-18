<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductEarlyAccessWindow>
 */
final class ProductEarlyAccessWindowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'available_from' => now()->subHour(),
            'available_until' => null,
            'audiences' => [],
        ];
    }
}
