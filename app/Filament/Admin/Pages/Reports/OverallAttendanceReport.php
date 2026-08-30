<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class OverallAttendanceReport extends InstructorReportPage
{
    protected static ?string $title = 'Overall Attendance Report';

    protected static ?string $slug = 'reports/instructor/overall-attendance';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('course_name')->label('Course Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('attendance_rate')->label('Attendance Rate')->sortable()->toggleable(),
                    TextColumn::make('excused_absences')->label('Excused Absences')->numeric()->sortable()->toggleable(),
                    TextColumn::make('unexcused_absences')->label('Unexcused Absences')->numeric()->sortable()->toggleable(),
                ])
                ->filters([$this->academicTermFilter()])
                ->defaultSort('course_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::OverallAttendance;
    }
}
