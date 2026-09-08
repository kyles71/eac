<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportCategory;
use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Filament\Admin\Widgets\Reports\FinanceOverview;
use App\Models\User;
use App\Services\Reports\FinanceReportService;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class FinanceReports extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Reports;

    protected static ?int $navigationSort = AdminNavigation::ReportsFinance;

    protected static ?string $navigationLabel = 'Finance';

    protected static ?string $title = 'Finance Reports';

    protected static ?string $slug = 'reports/finance';

    protected string $view = 'filament.admin.pages.reports.enrollment-reports';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportCategory::Finance->canView($user);
    }

    public function getSubheading(): string
    {
        return 'Review booked enrollment revenue, payroll events, and sick leave utilization, then save or export a filtered view.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('academic_term_id')
                ->label('Dashboard Academic Term')
                ->options(fn (): array => app(FinanceReportService::class)->academicTermOptions())
                ->default(fn (): ?int => app(FinanceReportService::class)->currentTerm()?->id)
                ->searchable()
                ->selectablePlaceholder(false)
                ->preload(),
        ]);
    }

    /** @return list<ReportKey> */
    public function availableReports(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return array_values(array_filter(
            ReportKey::cases(),
            fn (ReportKey $report): bool => $report->category() === ReportCategory::Finance
                && $report->canView($user),
        ));
    }

    public function dashboardWidgets(Schema $schema): Schema
    {
        $user = auth()->user();
        $widgets = $user instanceof User && ReportWidgetKey::FinanceOverview->canView($user)
            ? [FinanceOverview::class]
            : [];

        return $schema->components([
            Grid::make(1)
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($widgets))
                ->columnSpanFull(),
        ]);
    }
}
