<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BoardInteractionMode;
use App\Enums\BoardItemType;
use App\Models\Board;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Board>
 */
final class BoardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by_user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'slug' => fn (array $attributes): string => Str::slug($attributes['name']).'-'.fake()->unique()->randomNumber(5),
            'description' => fake()->sentence(),
            'interaction_mode' => BoardInteractionMode::Collaborative,
            'allowed_item_types' => [BoardItemType::Task->value],
            'archived_at' => null,
        ];
    }

    public function moderated(): static
    {
        return $this->state(fn (): array => [
            'interaction_mode' => BoardInteractionMode::Moderated,
            'allowed_item_types' => [
                BoardItemType::Bug->value,
                BoardItemType::FeatureRequest->value,
                BoardItemType::Idea->value,
            ],
        ]);
    }
}
