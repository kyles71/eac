<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormVersionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormVersion>
 */
final class FormVersionFactory extends Factory
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
            'version' => 1,
            'status' => FormVersionStatus::Draft,
            'schema' => [],
            'requires_signature' => false,
            'valid_until' => fake()->optional()->dateTimeBetween('now', '+1 year'),
            'published_by_id' => null,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FormVersionStatus::Published,
            'published_at' => now(),
        ]);
    }
}
