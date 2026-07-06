<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormAnswerType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormField>
 */
final class FormFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'key' => fake()->uuid(),
            'answer_type' => fake()->randomElement(FormAnswerType::cases()),
            'mapping' => null,
            'label' => fake()->sentence(3),
            'block_key' => null,
            'sub_key' => null,
        ];
    }
}
