<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StudentCommunicationType;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentCommunication>
 */
final class StudentCommunicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'event_id' => null,
            'author_id' => User::factory(),
            'type' => StudentCommunicationType::FirstAid,
            'stop_light_color' => null,
            'occurred_at' => fake()->dateTime(),
            'note' => fake()->paragraph(),
            'recipient_emails' => [fake()->safeEmail()],
            'queued_at' => now(),
        ];
    }
}
