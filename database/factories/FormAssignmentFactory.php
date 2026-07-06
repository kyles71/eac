<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Form;
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
            'form_id' => Form::factory(),
            'form_version_id' => FormVersion::factory(),
            'respondent_type' => User::class,
            'respondent_id' => User::factory(),
            'subject_type' => Student::class,
            'subject_id' => Student::factory(),
        ];
    }
}
