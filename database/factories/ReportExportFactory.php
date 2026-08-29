<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportKey;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExport>
 */
final class ReportExportFactory extends Factory
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
            'format' => ReportExportFormat::Csv,
            'status' => ReportExportStatus::Pending,
            'state' => [],
            'disk' => 'local',
            'file_name' => 'report-'.fake()->uuid(),
        ];
    }
}
