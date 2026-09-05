<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventSubstituteRequestStatus;
use App\Models\Event;
use App\Models\EventSubstituteCoverage;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSubstituteRequest>
 */
final class EventSubstituteRequestFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (EventSubstituteRequest $request): void {
            $coverage = $request->coverage;

            if (! $coverage instanceof EventSubstituteCoverage || $coverage->event_id !== $request->event_id) {
                $coverage = $request->event->activeSubstituteCoverages()->latest('id')->first()
                    ?? EventSubstituteCoverage::factory()
                        ->for($request->event)
                        ->create();
                $request->update(['event_substitute_coverage_id' => $coverage->id]);
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'event_substitute_coverage_id' => null,
            'teacher_id' => User::factory()->isTeacher(),
            'requested_by_user_id' => User::factory(),
            'status' => EventSubstituteRequestStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => EventSubstituteRequestStatus::Accepted,
            'responded_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => EventSubstituteRequestStatus::Declined,
            'responded_at' => now(),
            'closed_at' => now(),
        ]);
    }
}
