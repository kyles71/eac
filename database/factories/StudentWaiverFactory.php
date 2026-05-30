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
        $wantsTextUpdates = fake()->boolean();

        return [
            'student_name' => fake()->name(),
            'student_birth_date' => fake()->date(),
            'student_home_address' => fake()->address(),
            'student_email' => fake()->optional()->safeEmail(),
            'signer_name' => fake()->name(),
            'signer_relationship' => fake()->randomElement(['Mother', 'Father', 'Legal Guardian', 'Self - I am 18+', 'Other']),
            'contact_phone' => fake()->numerify('(###) ###-####'),
            'wants_text_updates' => $wantsTextUpdates,
            'text_update_phone' => $wantsTextUpdates ? fake()->numerify('(###) ###-####') : null,
            'contact_email' => fake()->safeEmail(),
            'heard_about' => fake()->randomElement(['From a friend', 'Facebook', 'Instagram', 'Other']),
            'medical_conditions' => fake()->sentence(),
            'allergies' => fake()->sentence(),
            'past_injuries' => fake()->sentence(),
            'medications' => fake()->sentence(),
            'medical_release_consent' => true,
            'behavioral_notes' => fake()->optional()->sentence(),
            'medical_release_signed_on' => fake()->date(),
            'health_safety_policy_consent' => true,
            'health_safety_policy_signed_on' => fake()->date(),
            'media_release_consent' => fake()->boolean(),
            'media_release_signed_on' => fake()->date(),
        ];
    }
}
