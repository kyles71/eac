<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventTeacherAssignmentMode;
use App\Models\Course;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
final class EventFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Event>
     */
    protected $model = Event::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $start_time = Carbon::create(fake()->dateTimeThisMonth('last day of this month'))
            ->setHours(fake()->numberBetween(21, 23))
            ->setMinutes(0)
            ->setSeconds(0);

        $end_time = $start_time->copy()->addMinutes(fake()->randomElement([30, 45, 60, 90, 120]));

        return [
            'name' => '',
            'description' => fake()->text(),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'course_id' => Course::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Event $event): void {
            if (empty($event->name) && $event->relationLoaded('course') === false) {
                $event->load('course');
            }

            if ($event->course !== null) {
                $event->name = $event->name !== '' ? $event->name : $event->course->name.' Class';
                $event->teacher_assignment_mode = EventTeacherAssignmentMode::CourseDefaults;
                $event->teacher_rotation_sequence = ((int) $event->course->events()
                    ->whereKeyNot($event->id)
                    ->max('teacher_rotation_sequence')) + 1;
                $event->save();
                $event->teachers()->sync($event->course->teachers()->pluck('users.id'));

                $teacherIds = $event->teachers()->pluck('users.id');

                if ($teacherIds->count() === 1) {
                    $event->substituteCoverages()
                        ->whereNull('covered_teacher_id')
                        ->update(['covered_teacher_id' => $teacherIds->first()]);
                }
            }
        });
    }

    public function standalone(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => fake()->sentence(3),
            'course_id' => null,
        ]);
    }
}
