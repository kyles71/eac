<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardItemComment>
 */
final class BoardItemCommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'board_item_id' => BoardItem::factory(),
            'author_id' => User::factory(),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'edited_at' => null,
        ];
    }
}
