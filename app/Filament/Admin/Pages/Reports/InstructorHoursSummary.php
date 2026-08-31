<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class InstructorHoursSummary extends InstructorReportPage
{
    protected static ?string $title = 'Instructor Hours Summary';

    protected static ?string $slug = 'reports/instructor/hours-summary';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('instructor_name')->label('Instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('event_count')->label('Events')->numeric()->sortable()->toggleable(),
                    TextColumn::make('scheduled_hours')->label('Scheduled Hours')->numeric(decimalPlaces: 2)->sortable()->toggleable(),
                    TextColumn::make('completed_hours')->label('Completed Hours')->numeric(decimalPlaces: 2)->sortable()->toggleable(),
                    TextColumn::make('upcoming_hours')->label('Upcoming Hours')->numeric(decimalPlaces: 2)->sortable()->toggleable(),
                    TextColumn::make('sub_hours_needed')
                        ->label('Sub Hours Needed')
                        ->tooltip('Scheduled hours from this instructor\'s assigned classes that were marked as needing substitute coverage, whether or not coverage was found.')
                        ->numeric(decimalPlaces: 2)
                        ->sortable()
                        ->toggleable(),
                    TextColumn::make('sub_hours_covered')
                        ->label('Sub Hours Covered')
                        ->tooltip('Scheduled hours assigned to this instructor as the confirmed substitute. Includes completed and upcoming non-cancelled events.')
                        ->numeric(decimalPlaces: 2)
                        ->sortable()
                        ->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    $this->instructorFilter(),
                    $this->dateRangeFilter(),
                ])
                ->defaultSort('instructor_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::InstructorHoursSummary;
    }
}
