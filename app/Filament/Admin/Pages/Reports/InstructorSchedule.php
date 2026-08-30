<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class InstructorSchedule extends InstructorReportPage
{
    protected static ?string $title = 'Instructor Schedule Report';

    protected static ?string $slug = 'reports/instructor/schedule';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('instructor_name')->label('Instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('course_name')->label('Course Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('day_of_week')->label('Day of Week')->sortable()->toggleable(),
                    TextColumn::make('start_time')->label('Start Time')->toggleable(),
                    TextColumn::make('end_time')->label('End Time')->toggleable(),
                    TextColumn::make('enrollment_count')->label('Enrollments')->numeric()->sortable()->toggleable(),
                    TextColumn::make('additional_instructors')->label('Additional Instructor Names')->searchable()->toggleable(),
                ])
                ->filters([$this->academicTermFilter(), $this->instructorFilter()])
                ->defaultSort('course_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::InstructorSchedule;
    }
}
