<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class InstructorClassAssignments extends InstructorReportPage
{
    protected static ?string $title = 'Instructor Class Assignments';

    protected static ?string $slug = 'reports/instructor/class-assignments';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('instructor_name')->label('Instructor')->searchable()->sortable()->toggleable(),
                    TextColumn::make('course_name')->label('Class')->searchable()->sortable()->toggleable(),
                    TextColumn::make('academic_term')->label('Academic Term')->sortable()->toggleable(),
                    TextColumn::make('role')->label('Assignment')->badge()->sortable()->toggleable(),
                    TextColumn::make('enrollment_count')->label('Enrollments')->numeric()->sortable()->toggleable(),
                    TextColumn::make('event_count')->label('Scheduled Events')->numeric()->sortable()->toggleable(),
                    TextColumn::make('first_meeting')->label('First Meeting')->sortable()->toggleable(),
                    TextColumn::make('last_meeting')->label('Last Meeting')->sortable()->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    $this->instructorFilter(),
                ])
                ->defaultSort('instructor_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::InstructorClassAssignments;
    }
}
