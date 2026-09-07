<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BoardItem;
use App\Models\BoardItemSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardItemSubscription>
 */
final class BoardItemSubscriptionFactory extends Factory
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
            'user_id' => User::factory(),
            'muted_at' => null,
        ];
    }
}
