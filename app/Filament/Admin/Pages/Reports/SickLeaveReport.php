<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use App\Services\Reports\FinanceReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SickLeaveReport extends FinanceReportPage
{
    protected static ?string $title = 'Sick Leave Report';

    protected static ?string $slug = 'reports/finance/sick-leave';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('instructor_name')->label('Instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('attribution_status')
                        ->label('Attribution Status')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Reconciled' ? 'success' : 'warning')
                        ->sortable()
                        ->toggleable(),
                    TextColumn::make('requested_by')->label('Requested By')->searchable()->sortable()->toggleable(),
                    TextColumn::make('sick_leave_date')->label('Sick Leave Date')->date('l, Y-m-d')->sortable()->toggleable(),
                    TextColumn::make('course_name')->label('Course Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('enrollment_count')->label('Number of Enrollments')->numeric()->sortable()->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    SelectFilter::make('attribution_status')
                        ->label('Attribution Status')
                        ->options(fn (): array => app(FinanceReportService::class)->attributionStatusOptions()),
                ])
                ->defaultSort('sick_leave_date'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::SickLeave;
    }
}
