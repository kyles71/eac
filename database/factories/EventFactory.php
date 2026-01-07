<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Event;
use Carbon\Carbon;

class EventFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Event::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $type = fake()->randomElement([Course::class]);

        $start_time = Carbon::create(fake()->dateTimeThisMonth('last day of this month'))
            ->setHours(fake()->numberBetween(17, 20))
            ->setMinutes(0)
            ->setSeconds(0);

        $end_time = $start_time->copy()->addMinutes(fake()->randomElement([30, 45, 60, 90, 120]));

        return [
            'name' => fake()->name(),
            'description' => fake()->text(),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'eventable_id' => $type::factory(),
            'eventable_type' => $type,
        ];
    }
}
