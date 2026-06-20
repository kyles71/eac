<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductQuestionType;
use App\Models\OrderItem;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductQuestionAnswer>
 */
final class ProductQuestionAnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'product_question_id' => ProductQuestion::factory(),
            'unit_number' => 1,
            'question' => fake()->sentence(),
            'question_type' => ProductQuestionType::Text,
            'was_required' => false,
            'question_order' => 0,
            'selected_option' => null,
            'answer' => fake()->sentence(),
        ];
    }
}
