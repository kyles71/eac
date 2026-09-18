<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\FinanceReportService;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

abstract class FinanceReportPage extends ReportPage
{
    /** @return class-string<Page> */
    protected static function getReportCategoryPage(): string
    {
        return FinanceReports::class;
    }

    protected function academicTermFilter(): SelectFilter
    {
        return SelectFilter::make('academic_term_id')
            ->label('Academic Term')
            ->options(fn (): array => app(FinanceReportService::class)->academicTermOptions())
            ->default(fn (): ?int => app(FinanceReportService::class)->currentTerm()?->id)
            ->searchable()
            ->preload();
    }

    protected function payrollDateRangeFilter(): Filter
    {
        return Filter::make('date_range')
            ->schema([
                DatePicker::make('from')
                    ->label('From')
                    ->default(fn (): string => app(FinanceReportService::class)->defaultPayrollDateFrom())
                    ->native(false),
                DatePicker::make('through')
                    ->label('Through')
                    ->default(fn (): string => app(FinanceReportService::class)->defaultPayrollDateThrough())
                    ->afterOrEqual('from')
                    ->native(false),
            ])
            ->columns(2)
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if (filled($data['from'] ?? null)) {
                    $indicators[] = 'From '.$data['from'];
                }

                if (filled($data['through'] ?? null)) {
                    $indicators[] = 'Through '.$data['through'];
                }

                return $indicators;
            });
    }
}
