<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductQuestionType;
use App\Models\Product;
use App\Models\ProductQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductQuestion>
 */
final class ProductQuestionFactory extends Factory
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
            'question' => fake()->sentence(),
            'type' => ProductQuestionType::Text,
            'is_required' => false,
            'max_length' => 255,
            'options' => null,
            'allows_other' => false,
            'sort_order' => 0,
        ];
    }

    public function required(): static
    {
        return $this->state(fn (): array => ['is_required' => true]);
    }

    /** @param list<string> $options */
    public function select(array $options = ['Small', 'Medium', 'Large'], bool $allowsOther = false): static
    {
        return $this->state(fn (): array => [
            'type' => ProductQuestionType::Select,
            'max_length' => null,
            'options' => $options,
            'allows_other' => $allowsOther,
        ]);
    }
}
