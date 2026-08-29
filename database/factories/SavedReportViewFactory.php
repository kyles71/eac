<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportKey;
use App\Enums\SavedReportViewVisibility;
use App\Models\SavedReportView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedReportView>
 */
final class SavedReportViewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'report_key' => ReportKey::TotalEnrollmentsByClass,
            'name' => fake()->unique()->words(3, true),
            'visibility' => SavedReportViewVisibility::Private,
            'state' => [
                'filters' => [],
                'search' => null,
                'sort' => null,
                'columns' => [],
            ],
        ];
    }
}
