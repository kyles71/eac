<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormPurpose;
use App\Enums\FormUpdateStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Form>
 */
final class FormFactory extends Factory
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
            'purpose' => $this->faker->randomElement(FormPurpose::cases()),
            'updates_allowed' => $this->faker->boolean(),
            'update_strategy' => $this->faker->randomElement(FormUpdateStrategy::cases()),
        ];
    }
}
