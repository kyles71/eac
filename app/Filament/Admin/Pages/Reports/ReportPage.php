<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Data\Reports\ReportDataset;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportKey;
use App\Filament\Admin\Pages\Reports\Concerns\InteractsWithSavedReportViews;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportDatasetResolverService;
use App\Support\MediaDisks;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

abstract class ReportPage extends Page implements HasTable
{
    use InteractsWithSavedReportViews;
    use InteractsWithTable;

    /** @var array<string, mixed>|null */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    /** @var string */
    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.reports.report';

    abstract protected static function getReportKey(): ReportKey;

    /** @return class-string<Page> */
    abstract protected static function getReportCategoryPage(): string;

    /** @param array<string, mixed> $filters */
    final public static function getUrlWithFilters(array $filters): string
    {
        return static::getUrl(['filters' => $filters]);
    }

    final public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && static::getReportKey()->canView($user);
    }

    final public function getSubheading(): string
    {
        return static::getReportKey()->description();
    }

    /** @return list<array<string, bool|float|int|string|null>> */
    final public function getReportFooterRows(): array
    {
        return $this->currentDataset()->footerRows;
    }

    /** @return array<string, string> */
    final public function getReportHeaders(): array
    {
        return $this->currentDataset()->headers;
    }

    protected function configureReportTable(Table $table): Table
    {
        return $table
            ->records(fn (
                ?string $sortColumn,
                ?string $sortDirection,
                ?string $search,
                array $filters,
                int $page,
                int $recordsPerPage,
            ): LengthAwarePaginator => $this->reportPaginator(
                $filters,
                $search,
                $sortColumn,
                $sortDirection,
                $page,
                $recordsPerPage,
            ))
            ->searchable()
            ->reorderableColumns()
            ->persistColumnsInSession()
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No report rows match these filters')
            ->emptyStateDescription('Choose different report filters and try again.');
    }

    protected function reportKey(): ReportKey
    {
        return static::getReportKey();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        $categoryPage = static::getReportCategoryPage();

        return [
            Action::make('backToReportCategory')
                ->label($this->reportKey()->category()->getLabel())
                ->icon(Heroicon::ArrowLeft)
                ->url($categoryPage::getUrl()),
            $this->loadReportViewAction(),
            $this->saveReportViewAction(),
            $this->deleteReportViewAction(),
            Action::make('export')
                ->label('Export')
                ->icon(Heroicon::ArrowDownTray)
                ->schema([
                    Select::make('format')
                        ->options(ReportExportFormat::class)
                        ->default(ReportExportFormat::Csv->value)
                        ->selectablePlaceholder(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User && $this->reportKey()->canView($user), 403);

                    $format = $data['format'] instanceof ReportExportFormat
                        ? $data['format']
                        : ReportExportFormat::from((string) $data['format']);
                    $export = ReportExport::query()->create([
                        'user_id' => $user->id,
                        'report_key' => $this->reportKey(),
                        'format' => $format,
                        'status' => ReportExportStatus::Pending,
                        'state' => $this->reportViewState(),
                        'disk' => MediaDisks::private(),
                        'file_name' => Str::slug($this->reportKey()->label())
                            .'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)),
                    ]);

                    GenerateReportExport::dispatch($export)->afterCommit();

                    if (config('queue.default') !== 'sync') {
                        Notification::make()
                            ->title('Report export queued')
                            ->body('You will receive a private download notification when it is ready.')
                            ->success()
                            ->send();
                    }
                }),
        ];
    }

    protected function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @param array<string, mixed> $filters */
    private function reportPaginator(
        array $filters,
        ?string $search,
        ?string $sortColumn,
        ?string $sortDirection,
        int $page,
        int $recordsPerPage,
    ): LengthAwarePaginator {
        $sort = filled($sortColumn)
            ? $sortColumn.':'.($sortDirection === 'desc' ? 'desc' : 'asc')
            : null;
        $rows = app(ReportDatasetResolverService::class)
            ->dataset($this->reportKey(), $this->authenticatedUser(), $filters)
            ->rowsFor($search, $sort);
        $offset = ($page - 1) * $recordsPerPage;
        $items = collect(array_slice($rows, $offset, $recordsPerPage))
            ->mapWithKeys(fn (array $row, int $index): array => [
                (string) ($row['_key'] ?? "row_{$offset}_{$index}") => $row,
            ]);

        return new LengthAwarePaginator(
            $items,
            count($rows),
            $recordsPerPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => $this->getTablePaginationPageName(),
            ],
        );
    }

    private function currentDataset(): ReportDataset
    {
        return app(ReportDatasetResolverService::class)->dataset(
            $this->reportKey(),
            $this->authenticatedUser(),
            $this->tableFilters ?? [],
        );
    }
}
