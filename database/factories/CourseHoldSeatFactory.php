<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseHoldSeat>
 */
final class CourseHoldSeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_hold_id' => CourseHold::factory(),
            'course_id' => Course::factory(),
            'student_id' => null,
            'locked_unit_price' => fake()->numberBetween(5000, 50000),
            'claimed_order_item_id' => null,
            'released_at' => null,
            'released_by_user_id' => null,
        ];
    }
}
