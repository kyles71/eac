<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\EnrollmentReportService;
use Filament\Pages\Page;
use Filament\Tables\Filters\SelectFilter;

abstract class EnrollmentReportPage extends ReportPage
{
    /** @return class-string<Page> */
    protected static function getReportCategoryPage(): string
    {
        return EnrollmentReports::class;
    }

    protected function academicTermFilter(): SelectFilter
    {
        return SelectFilter::make('academic_term_id')
            ->label('Academic Term')
            ->options(fn (): array => app(EnrollmentReportService::class)->academicTermOptions())
            ->default(fn (): ?int => app(EnrollmentReportService::class)->currentTerm()?->id)
            ->searchable()
            ->preload();
    }

    protected function competitionSeasonFilter(): SelectFilter
    {
        return SelectFilter::make('competition_season_id')
            ->label('Competition Season')
            ->options(fn (): array => app(EnrollmentReportService::class)->competitionSeasonOptions())
            ->default(fn (): ?int => app(EnrollmentReportService::class)->currentCompetitionSeason()?->id)
            ->searchable()
            ->preload();
    }

    protected function courseFilter(): SelectFilter
    {
        return SelectFilter::make('course_id')
            ->label('Enrolled in Course')
            ->options(fn (): array => app(EnrollmentReportService::class)
                ->courseOptions($this->authenticatedUser()))
            ->searchable()
            ->preload();
    }

    protected function competitionTeamFilter(): SelectFilter
    {
        return SelectFilter::make('competition_team_id')
            ->label('Competition Team')
            ->options(fn (): array => app(EnrollmentReportService::class)->competitionTeamOptions())
            ->searchable()
            ->preload();
    }
}
