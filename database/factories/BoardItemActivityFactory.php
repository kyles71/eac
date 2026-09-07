<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BoardItemActivityType;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardItemActivity>
 */
final class BoardItemActivityFactory extends Factory
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
            'actor_id' => User::factory(),
            'type' => BoardItemActivityType::Created,
            'metadata' => null,
        ];
    }
}
