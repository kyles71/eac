<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BoardStageKind;
use App\Models\Board;
use App\Models\BoardStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardStage>
 */
final class BoardStageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => fake()->unique()->words(2, true),
            'subtitle' => null,
            'color' => 'gray',
            'sort_order' => fake()->unique()->numberBetween(1, 30000),
            'kind' => BoardStageKind::Active,
            'is_default' => false,
            'archived_at' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
