<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\Reports\ReportDataset;
use App\Enums\ReportCategory;
use App\Enums\ReportKey;
use App\Models\User;
use InvalidArgumentException;

final readonly class ReportDatasetResolverService
{
    public function __construct(
        private EnrollmentReportService $enrollmentReports,
        private InstructorReportService $instructorReports,
    ) {}

    /** @param array<string, mixed> $filters */
    public function dataset(ReportKey $report, User $user, array $filters): ReportDataset
    {
        return match ($report->category()) {
            ReportCategory::Enrollment => $this->enrollmentReports->dataset($report, $user, $filters),
            ReportCategory::Instructor => $this->instructorReports->dataset($report, $user, $filters),
            default => throw new InvalidArgumentException("{$report->label()} does not have a dataset provider."),
        };
    }
}
