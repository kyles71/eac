<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormAssignment;
use App\Models\FormVersion;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormAssignment>
 */
final class FormAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_version_id' => FormVersion::factory(),
            'form_id' => fn (array $attributes): int => FormVersion::query()
                ->findOrFail($attributes['form_version_id'])
                ->form_id,
            'respondent_type' => User::class,
            'subject_type' => Student::class,
            'subject_id' => Student::factory(),
            'respondent_id' => fn (array $attributes): int => Student::query()
                ->findOrFail($attributes['subject_id'])
                ->user_id,
            'is_manually_assigned' => false,
            'manually_assigned_by_id' => null,
            'manually_assigned_at' => null,
        ];
    }
}
