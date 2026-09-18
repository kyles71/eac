<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Kyle\FilamentFormBuilder\Enums\FormUpdateStrategy;

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
            'key' => Str::slug($this->faker->unique()->words(3, true)),
            'name' => $this->faker->sentence(3),
            'updates_allowed' => $this->faker->boolean(),
            'update_strategy' => $this->faker->randomElement(FormUpdateStrategy::cases()),
        ];
    }
}
