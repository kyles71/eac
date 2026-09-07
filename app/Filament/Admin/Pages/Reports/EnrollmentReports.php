<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportCategory;
use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Filament\Admin\Widgets\Reports\CapacityMetricChart;
use App\Filament\Admin\Widgets\Reports\EnrollmentOverview;
use App\Models\User;
use App\Services\Reports\EnrollmentReportService;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use UnitEnum;

final class EnrollmentReports extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Reports;

    protected static ?int $navigationSort = AdminNavigation::ReportsEnrollment;

    protected static ?string $navigationLabel = 'Enrollment';

    protected static ?string $title = 'Enrollment Reports';

    protected static ?string $slug = 'reports/enrollment';

    protected string $view = 'filament.admin.pages.reports.enrollment-reports';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportCategory::Enrollment->canView($user);
    }

    public function getSubheading(): string
    {
        return 'Review enrollment health, open a detailed report, save repeatable views, and export private files.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('academic_term_id')
                ->label('Dashboard Academic Term')
                ->options(fn (): array => app(EnrollmentReportService::class)->academicTermOptions())
                ->default(fn (): ?int => app(EnrollmentReportService::class)->currentTerm()?->id)
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
            fn (ReportKey $report): bool => $report->category() === ReportCategory::Enrollment
                && $report->canView($user),
        ));
    }

    public function dashboardWidgets(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'lg' => 2,
            ])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->dashboardWidgetConfigurations()))
                ->columnSpanFull(),
        ]);
    }

    /** @return list<class-string<Widget>|WidgetConfiguration> */
    private function dashboardWidgetConfigurations(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $widgets = [];

        if (ReportWidgetKey::EnrollmentOverview->canView($user)) {
            $widgets[] = EnrollmentOverview::class;
        }

        if (! ReportWidgetKey::EnrollmentCapacityMetrics->canView($user)) {
            return $widgets;
        }

        foreach (app(EnrollmentReportService::class)->configuredCapacityMetrics() as $capacityMetric) {
            $widgets[] = CapacityMetricChart::make([
                'metricName' => $capacityMetric['name'],
                'tagSlugs' => $capacityMetric['tag_slugs'],
            ]);
        }

        return $widgets;
    }
}
