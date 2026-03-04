<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventAttendee> */
final class EventAttendeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'attendee_type' => (new Student)->getMorphClass(),
            'attendee_id' => Student::factory(),
            'attended' => fake()->boolean(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendee_type' => (new User)->getMorphClass(),
            'attendee_id' => $user?->id ?? User::factory(),
        ]);
    }

    public function forStudent(?Student $student = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'attendee_type' => (new Student)->getMorphClass(),
            'attendee_id' => $student?->id ?? Student::factory(),
        ]);
    }
}
