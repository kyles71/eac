<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CompetitionEnrollments extends EnrollmentReportPage
{
    protected static ?string $title = 'Competition Enrollments';

    protected static ?string $slug = 'reports/enrollment/competition-enrollments';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('dancer_name')->label('Dancer Name')->searchable()->sortable()->toggleable(),
                    TextColumn::make('competition_team')->label('Competition Team')->searchable()->sortable()->toggleable(),
                    TextColumn::make('course_name')->label('Enrolled Course')->searchable()->sortable()->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    $this->competitionSeasonFilter(),
                ])
                ->defaultSort('dancer_name'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::CompetitionEnrollments;
    }
}
