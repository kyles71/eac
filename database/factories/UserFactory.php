<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
final class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    private static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Give the user the super admin role.
     */
    public function isSuperAdmin(): Factory
    {
        return $this->afterMaking(function (User $user) {
            $user->assignRole('super_admin');
        })->afterCreating(function (User $user) {
            $user->assignRole('super_admin');
            $user->attachTags([
                Calendar::AUDIENCE_TAG_OWNERS,
                Calendar::AUDIENCE_TAG_STAFF,
                Calendar::AUDIENCE_TAG_COMP,
            ], Calendar::AUDIENCE_TAG_TYPE);
        });
    }

    /**
     * Give the user the owner role.
     */
    public function isOwner(): Factory
    {
        return $this->afterMaking(function (User $user) {
            $user->assignRole('owner');
        })->afterCreating(function (User $user) {
            $user->assignRole('owner');
            $user->attachTags([
                Calendar::AUDIENCE_TAG_OWNERS,
                Calendar::AUDIENCE_TAG_STAFF,
                Calendar::AUDIENCE_TAG_COMP,
            ], Calendar::AUDIENCE_TAG_TYPE);
        });
    }

    /**
     * Give the user the teacher role.
     */
    public function isTeacher(): Factory
    {
        return $this->afterMaking(function (User $user) {
            $user->assignRole('teacher');
        })->afterCreating(function (User $user) {
            $user->assignRole('teacher');
            $user->attachTag(Calendar::AUDIENCE_TAG_STAFF, Calendar::AUDIENCE_TAG_TYPE);
        });
    }
}
