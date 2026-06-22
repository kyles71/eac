<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CalendarAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Calendar>
 */
final class CalendarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'background_color' => fake()->optional()->hexColor(),
            'access' => CalendarAccess::Restricted,
        ];
    }
}
