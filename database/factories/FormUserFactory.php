<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormUser> */
final class FormUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'user_id' => User::factory(),
            'student_id' => null,
            'responseable_type' => null,
            'responseable_id' => null,
            'signature' => fake()->name(),
            'date_signed' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function forStudent(?Student $student = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'student_id' => $student?->id ?? Student::factory(),
        ]);
    }

    public function unsigned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'signature' => null,
            'date_signed' => null,
        ]);
    }
}
