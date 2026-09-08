<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormAnswer>
 */
final class FormAnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_response_id' => FormResponse::factory(),
            'form_field_id' => FormField::factory(),
            'form_answer_group_id' => null,
            'value_string' => fake()->word(),
        ];
    }
}
