<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BoardItemPriority;
use App\Enums\BoardItemType;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\BoardStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardItem>
 */
final class BoardItemFactory extends Factory
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
            'board_stage_id' => fn (array $attributes): int => BoardStage::factory()->create([
                'board_id' => $attributes['board_id'],
                'is_default' => true,
            ])->id,
            'created_by_user_id' => User::factory(),
            'type' => BoardItemType::Task,
            'priority' => BoardItemPriority::Medium,
            'title' => fake()->sentence(5),
            'description' => '<p>'.fake()->paragraph().'</p>',
            'position' => (string) fake()->unique()->numberBetween(1, 1000000),
            'due_date' => null,
            'related_url' => null,
            'archived_at' => null,
        ];
    }
}
