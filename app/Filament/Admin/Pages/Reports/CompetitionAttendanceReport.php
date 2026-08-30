<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CompetitionAttendanceReport extends InstructorReportPage
{
    protected static ?string $title = 'Competition Attendance Report';

    protected static ?string $slug = 'reports/instructor/competition-attendance';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('dancer_name')->label('Dancer Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('course_name')->label('Course')->searchable()->sortable()->toggleable(),
                    TextColumn::make('attendance_rate')->label('Attendance Rate')->sortable()->toggleable(),
                    TextColumn::make('excused_absences')->label('Excused Absences')->numeric()->sortable()->toggleable(),
                    TextColumn::make('unexcused_absences')->label('Unexcused Absences')->numeric()->sortable()->toggleable(),
                ])
                ->filters([$this->competitionSeasonFilter(), $this->academicTermFilter()])
                ->defaultSort('dancer_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::CompetitionAttendance;
    }
}
