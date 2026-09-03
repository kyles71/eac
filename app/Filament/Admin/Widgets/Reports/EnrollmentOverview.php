<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Reports;

use App\Enums\ReportWidgetKey;
use App\Filament\Admin\Pages\Reports\TotalEnrollmentsByClass;
use App\Filament\Admin\Resources\Enrollments\EnrollmentResource;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Services\Reports\EnrollmentReportService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class EnrollmentOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportWidgetKey::EnrollmentOverview->canView($user);
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! ReportWidgetKey::EnrollmentOverview->canView($user)) {
            return [];
        }

        $service = app(EnrollmentReportService::class);
        $term = $this->selectedTerm($service);
        $metrics = $service->dashboard($term, $user);
        $termName = $term instanceof AcademicTerm
            ? $term->display_name
            : 'No current term';
        $classReportUrl = TotalEnrollmentsByClass::getUrlWithFilters([
            'academic_term_id' => ['value' => $term?->id],
        ]);
        $stats = [
            Stat::make('Enrollments', number_format($metrics['enrollment_count']))
                ->description($termName)
                ->descriptionIcon(Heroicon::OutlinedAcademicCap)
                ->url($classReportUrl),
            Stat::make('Capacity Used', $this->formatPercentage($metrics['capacity_percentage']))
                ->description(number_format($metrics['enrollment_count']).' of '.number_format($metrics['total_capacity']))
                ->descriptionIcon(Heroicon::OutlinedChartPie)
                ->url($classReportUrl),
            Stat::make('Dancers', number_format($metrics['dancer_count']))
                ->description(number_format($metrics['average_classes_per_dancer'], 2).' classes per dancer')
                ->descriptionIcon(Heroicon::OutlinedUsers),
            Stat::make('Sold Out', number_format($metrics['sold_out_count']))
                ->description('*includes Competition team courses')
                ->color($metrics['sold_out_count'] > 0 ? 'danger' : 'gray')
                ->url($this->capacityStatusUrl($term, 'sold_out')),
            Stat::make('Near Sold Out', number_format($metrics['near_sold_out_count']))
                ->description('*includes Competition team courses')
                ->color($metrics['near_sold_out_count'] > 0 ? 'warning' : 'gray')
                ->url($this->capacityStatusUrl($term, 'near_sold_out')),
            Stat::make('Not Running', number_format($metrics['not_running_count']))
                ->description('Classes below the configured threshold')
                ->color($metrics['not_running_count'] > 0 ? 'warning' : 'gray')
                ->url($this->capacityStatusUrl($term, 'not_running')),
            Stat::make('Unassigned Enrollments', number_format($metrics['unassigned_count']))
                ->description('Seats without an assigned dancer')
                ->color($metrics['unassigned_count'] > 0 ? 'warning' : 'gray')
                ->url(EnrollmentResource::getUrl('index', ['tab' => 'open'])),
        ];

        if ($metrics['target_remaining'] !== null) {
            $stats[] = Stat::make('To Enrollment Target', number_format($metrics['target_remaining']))
                ->description($metrics['target_remaining'] === 0 ? 'Target reached' : 'Enrollments remaining')
                ->color($metrics['target_remaining'] === 0 ? 'success' : 'gray');
        }

        if ($metrics['stretch_remaining'] !== null) {
            $stats[] = Stat::make('To Stretch Goal', number_format($metrics['stretch_remaining']))
                ->description($metrics['stretch_remaining'] === 0 ? 'Stretch goal reached' : 'Enrollments remaining')
                ->color($metrics['stretch_remaining'] === 0 ? 'success' : 'gray');
        }

        return $stats;
    }

    private function selectedTerm(EnrollmentReportService $service): ?AcademicTerm
    {
        $id = filter_var($this->pageFilters['academic_term_id'] ?? null, FILTER_VALIDATE_INT);

        return $id === false
            ? $service->currentTerm()
            : AcademicTerm::query()->find($id);
    }

    private function capacityStatusUrl(?AcademicTerm $term, string $capacityStatus): string
    {
        return TotalEnrollmentsByClass::getUrlWithFilters([
            'academic_term_id' => ['value' => $term?->id],
            'capacity_status' => ['value' => $capacityStatus],
        ]);
    }

    private function formatPercentage(?float $percentage): string
    {
        return $percentage === null ? '—' : number_format($percentage, 1).'%';
    }
}
