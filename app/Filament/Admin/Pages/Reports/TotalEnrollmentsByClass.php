<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use App\Services\Reports\EnrollmentReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class TotalEnrollmentsByClass extends EnrollmentReportPage
{
    protected static ?string $title = 'Total Enrollments by Class';

    protected static ?string $slug = 'reports/enrollment/total-enrollments-by-class';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('course_name')->label('Course Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('enrollment_count')->label('Enrollments')->numeric()->sortable()->toggleable(),
                    TextColumn::make('capacity')->numeric()->sortable()->toggleable(),
                    TextColumn::make('available')->numeric()->sortable()->toggleable(),
                    TextColumn::make('utilization')->sortable()->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    SelectFilter::make('capacity_status')
                        ->label('Capacity Status')
                        ->options([
                            'sold_out' => 'Sold Out',
                            'not_running' => 'Not Running',
                            'near_sold_out' => 'Near Sold Out',
                        ]),
                    SelectFilter::make('course_tag')
                        ->label('Course Tag')
                        ->options(fn (): array => app(EnrollmentReportService::class)->courseTagOptions())
                        ->searchable()
                        ->preload(),
                ])
                ->defaultSort('course_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::TotalEnrollmentsByClass;
    }
}
