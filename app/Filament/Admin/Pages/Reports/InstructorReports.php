<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportCategory;
use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Filament\Admin\Widgets\Reports\InstructorOverview;
use App\Models\User;
use App\Services\Reports\InstructorReportService;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class InstructorReports extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Reports;

    protected static ?int $navigationSort = AdminNavigation::ReportsInstructor;

    protected static ?string $navigationLabel = 'Instructor';

    protected static ?string $title = 'Instructor Reports';

    protected static ?string $slug = 'reports/instructor';

    protected string $view = 'filament.admin.pages.reports.enrollment-reports';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportCategory::Instructor->canView($user);
    }

    public function getSubheading(): string
    {
        return 'Review teaching assignments, scheduled hours, and substitute coverage, then save or export a filtered view.';
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('academic_term_id')
                ->label('Dashboard Academic Term')
                ->options(fn (): array => app(InstructorReportService::class)->academicTermOptions())
                ->default(fn (): ?int => app(InstructorReportService::class)->currentTerm()?->id)
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
            fn (ReportKey $report): bool => $report->category() === ReportCategory::Instructor
                && $report->canView($user),
        ));
    }

    public function dashboardWidgets(Schema $schema): Schema
    {
        $user = auth()->user();
        $widgets = $user instanceof User && ReportWidgetKey::InstructorOverview->canView($user)
            ? [InstructorOverview::class]
            : [];

        return $schema->components([
            Grid::make(1)
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($widgets))
                ->columnSpanFull(),
        ]);
    }
}
