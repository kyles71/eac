<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StudentWaiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentWaiver> */
final class StudentWaiverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'medical_conditions' => fake()->optional()->sentence(),
            'allergies' => fake()->optional()->sentence(),
        ];
    }
}
