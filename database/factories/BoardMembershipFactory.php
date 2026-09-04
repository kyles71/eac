<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BoardMemberRole;
use App\Models\Board;
use App\Models\BoardMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardMembership>
 */
final class BoardMembershipFactory extends Factory
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
            'user_id' => User::factory(),
            'role' => BoardMemberRole::Contributor,
        ];
    }

    public function viewer(): static
    {
        return $this->state(fn (): array => ['role' => BoardMemberRole::Viewer]);
    }

    public function manager(): static
    {
        return $this->state(fn (): array => ['role' => BoardMemberRole::Manager]);
    }
}
