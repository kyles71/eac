<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ClassAttendanceReport extends InstructorReportPage
{
    protected static ?string $title = 'Class Attendance Report';

    protected static ?string $slug = 'reports/instructor/class-attendance';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('dancer_name')->label('Dancer Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('attended')->numeric()->sortable()->toggleable(),
                    TextColumn::make('late')->numeric()->sortable()->toggleable(),
                    TextColumn::make('excused_absence')->label('Excused Absence')->numeric()->sortable()->toggleable(),
                    TextColumn::make('unexcused_absence')->label('Unexcused Absence')->numeric()->sortable()->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    $this->courseFilter(),
                    $this->dateRangeFilter(),
                ])
                ->defaultSort('dancer_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::ClassAttendance;
    }
}
