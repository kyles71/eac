<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Costume;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Costume>
 */
final class CostumeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'name' => fake()->words(2, true).' costume',
            'vendor' => fake()->optional()->company(),
            'vendor_number' => fake()->optional()->bothify('CST-####'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
