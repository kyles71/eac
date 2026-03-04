<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmergencyContact;
use App\Models\StudentWaiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmergencyContact> */
final class EmergencyContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_waiver_id' => StudentWaiver::factory(),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['Mother', 'Father', 'Guardian', 'Grandparent', 'Sibling']),
            'phone_number' => fake()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
        ];
    }
}
