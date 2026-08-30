<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Reports;

use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Services\Reports\InstructorReportService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InstructorOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportWidgetKey::InstructorOverview->canView($user);
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! ReportWidgetKey::InstructorOverview->canView($user)) {
            return [];
        }

        $service = app(InstructorReportService::class);
        $term = $this->selectedTerm($service);
        $metrics = $service->dashboard($term, $user);
        $termName = $term instanceof AcademicTerm
            ? $term->display_name
            : 'No current term';
        $filters = $this->reportFilters($term);

        $stats = [
            Stat::make('Overall Attendance Rate', $this->formatPercentage($metrics['overall_attendance_rate']))
                ->description('Present or late, through today')
                ->descriptionIcon(Heroicon::OutlinedChartPie)
                ->url($this->reportUrl($user, ReportKey::OverallAttendance, [
                    'academic_term_id' => ['value' => $term?->id],
                ])),
            Stat::make('Instructors', number_format($metrics['instructor_count']))
                ->description($termName)
                ->descriptionIcon(Heroicon::OutlinedUserGroup)
                ->url($this->reportUrl($user, ReportKey::InstructorHoursSummary, $filters)),
            Stat::make('Scheduled Events', number_format($metrics['scheduled_event_count']))
                ->description(number_format($metrics['scheduled_hours'], 2).' staff hours')
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->url($this->reportUrl($user, ReportKey::InstructorTeachingSchedule, $filters)),
            Stat::make('Completed Hours', number_format($metrics['completed_hours'], 2))
                ->description('Cancelled events excluded')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->url($this->reportUrl($user, ReportKey::InstructorHoursSummary, $filters)),
            Stat::make('Substitute Events', number_format($metrics['substitute_event_count']))
                ->description(number_format($metrics['substitute_hours'], 2).' confirmed scheduled hours')
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->url($this->reportUrl($user, ReportKey::SubstituteCoverage, $filters)),
            Stat::make('Needs Coverage', number_format($metrics['needs_coverage_count']))
                ->description('No confirmed substitute')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($metrics['needs_coverage_count'] > 0 ? 'danger' : 'gray')
                ->url($this->reportUrl($user, ReportKey::SubstituteCoverage, $filters)),
            Stat::make('Cancelled Events', number_format($metrics['cancelled_event_count']))
                ->description('Excluded from instructor hours')
                ->descriptionIcon(Heroicon::OutlinedXCircle)
                ->color($metrics['cancelled_event_count'] > 0 ? 'warning' : 'gray'),
        ];

        if (! $user->hasCourseRestrictedAdminAccess()) {
            $stats[] = Stat::make('Overall Sub Rate', $this->formatPercentage($metrics['overall_sub_rate']))
                ->description('Events requiring a sub through today')
                ->descriptionIcon(Heroicon::OutlinedUserPlus)
                ->url($this->reportUrl($user, ReportKey::SubstituteCoverage, $filters));
        }

        return $stats;
    }

    private function selectedTerm(InstructorReportService $service): ?AcademicTerm
    {
        $id = filter_var($this->pageFilters['academic_term_id'] ?? null, FILTER_VALIDATE_INT);

        return $id === false
            ? $service->currentTerm()
            : AcademicTerm::query()->find($id);
    }

    /** @return array<string, array<string, int|string|null>> */
    private function reportFilters(?AcademicTerm $term): array
    {
        return [
            'academic_term_id' => ['value' => $term?->id],
            'date_range' => [
                'from' => $term?->starts_on->toDateString(),
                'through' => $term?->ends_on->toDateString(),
            ],
        ];
    }

    private function formatPercentage(?float $percentage): string
    {
        return $percentage === null ? '—' : number_format($percentage, 1).'%';
    }

    /** @param array<string, mixed> $filters */
    private function reportUrl(User $user, ReportKey $report, array $filters): ?string
    {
        if (! $report->canView($user)) {
            return null;
        }

        $page = $report->page();

        return $page::getUrlWithFilters($filters);
    }
}
