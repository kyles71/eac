<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomElement([2500, 5000, 7500, 10000, 12500, 15000]),
            'is_active' => true,
            'requires_course_id' => null,
            'productable_type' => null,
            'productable_id' => null,
        ];
    }

    /**
     * Create a product linked to a Course.
     */
    public function forCourse(?Course $course = null): static
    {
        return $this->state(function (array $attributes) use ($course): array {
            $course ??= Course::factory()->create();

            return [
                'name' => $course->name,
                'productable_type' => Course::class,
                'productable_id' => $course->id,
            ];
        });
    }

    /**
     * Mark the product as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
