<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HolidayEventScope;
use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
final class HolidayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('+1 month', '+1 year');

        return [
            'name' => fake()->randomElement(['Thanksgiving', 'Winter Break', 'Spring Break']),
            'starts_on' => $startsOn,
            'ends_on' => $startsOn,
            'scope' => HolidayEventScope::AllEvents,
        ];
    }
}
