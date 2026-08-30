<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class InstructorTeachingSchedule extends InstructorReportPage
{
    protected static ?string $title = 'Instructor Teaching Schedule';

    protected static ?string $slug = 'reports/instructor/teaching-schedule';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('date')->date('l, Y-m-d')->sortable()->toggleable(),
                    TextColumn::make('start_time')->label('Starts')->toggleable(),
                    TextColumn::make('end_time')->label('Ends')->toggleable(),
                    TextColumn::make('instructor_name')->label('Instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('course_name')->label('Class')->searchable()->sortable()->toggleable(),
                    TextColumn::make('enrollment_count')->label('Number of Enrollments')->numeric()->sortable()->toggleable(),
                    TextColumn::make('academic_term')->label('Academic Term')->sortable()->toggleable(),
                    TextColumn::make('role')->label('Teaching Role')->badge()->sortable()->toggleable(),
                    TextColumn::make('hours')
                        ->numeric(decimalPlaces: 2)
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('status')
                        ->label('Event Status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'Completed' => 'success',
                            'In Progress' => 'warning',
                            default => 'gray',
                        })
                        ->sortable()
                        ->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    $this->instructorFilter(),
                    $this->dateRangeFilter(),
                ])
                ->defaultSort('date'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::InstructorTeachingSchedule;
    }
}
