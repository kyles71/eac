<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormAnswerGroup;
use App\Models\FormResponse;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormAnswerGroup>
 */
final class FormAnswerGroupFactory extends Factory
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
            'form_version_id' => FormVersion::factory(),
            'form_response_id' => FormResponse::factory(),
            'block_key' => fake()->uuid(),
            'position' => 0,
        ];
    }
}
