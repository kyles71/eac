<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;

/**
 * @extends Factory<FormResponse>
 */
final class FormResponseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_assignment_id' => FormAssignment::factory(),
            'form_version_id' => FormVersion::factory(),
            'revision_of_id' => null,
            'status' => FormResponseStatus::Draft,
            'signature' => null,
            'date_signed' => null,
            'projection_type' => null,
            'projection_id' => null,
            'submitted_at' => null,
        ];
    }
}
