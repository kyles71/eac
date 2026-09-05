<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Reports;

use App\Enums\ReportWidgetKey;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Services\Reports\FinanceReportService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class FinanceOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

    protected ?string $description = 'Booked course sales. Refunds are not included in either amount.';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportWidgetKey::FinanceOverview->canView($user);
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! ReportWidgetKey::FinanceOverview->canView($user)) {
            return [];
        }

        $service = app(FinanceReportService::class);
        $term = $this->selectedTerm($service);
        $metrics = $service->dashboard($term);
        $termName = $term instanceof AcademicTerm ? $term->display_name : 'No current term';

        return [
            Stat::make('Gross Enrollments', format_money($metrics['gross_enrollments']))
                ->description($termName.' · Before discounts and store credit')
                ->descriptionIcon(Heroicon::OutlinedBanknotes),
            Stat::make('Net Enrollment Purchases', format_money($metrics['net_enrollment_purchases']))
                ->description($termName.' · After discounts and store credit')
                ->descriptionIcon(Heroicon::OutlinedReceiptPercent),
        ];
    }

    private function selectedTerm(FinanceReportService $service): ?AcademicTerm
    {
        $id = filter_var($this->pageFilters['academic_term_id'] ?? null, FILTER_VALIDATE_INT);

        return $id === false
            ? $service->currentTerm()
            : AcademicTerm::query()->find($id);
    }
}
