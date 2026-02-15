<?php

namespace Database\Factories;

use App\Enums\FormTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Form>
 */
class FormFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'form_type' => $this->faker->randomElement(array_column(FormTypes::cases(), 'value')),
            'can_update' => $this->faker->boolean(),
            'valid_until' => $this->faker->optional()->dateTimeBetween('now', '+1 year'),
        ];
    }
}
