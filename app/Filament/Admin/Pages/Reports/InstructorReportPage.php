<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Services\Reports\InstructorReportService;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;

abstract class InstructorReportPage extends ReportPage
{
    /** @return class-string<Page> */
    protected static function getReportCategoryPage(): string
    {
        return InstructorReports::class;
    }

    protected function academicTermFilter(bool $defaultToCurrentTerm = true): SelectFilter
    {
        $filter = SelectFilter::make('academic_term_id')
            ->label('Academic Term')
            ->options(fn (): array => app(InstructorReportService::class)->academicTermOptions())
            ->searchable()
            ->preload();

        return $defaultToCurrentTerm
            ? $filter->default(fn (): ?int => app(InstructorReportService::class)->currentTerm()?->id)
            : $filter;
    }

    protected function instructorFilter(): SelectFilter
    {
        return SelectFilter::make('instructor_id')
            ->label('Instructor')
            ->options(fn (): array => app(InstructorReportService::class)
                ->instructorOptions($this->authenticatedUser()))
            ->default(fn (): ?int => $this->authenticatedUser()->hasCourseRestrictedAdminAccess()
                ? $this->authenticatedUser()->id
                : null)
            ->searchable()
            ->preload();
    }

    protected function courseFilter(bool $defaultToFirstCourse = true): SelectFilter
    {
        $filter = SelectFilter::make('course_id')
            ->label('Course')
            ->options(fn (): array => app(InstructorReportService::class)
                ->courseOptions($this->authenticatedUser()))
            ->searchable()
            ->preload();

        return $defaultToFirstCourse
            ? $filter->default(fn (): ?int => app(InstructorReportService::class)
                ->defaultCourseId($this->authenticatedUser()))
            : $filter;
    }

    protected function competitionSeasonFilter(): SelectFilter
    {
        return SelectFilter::make('competition_season_id')
            ->label('Competition Season')
            ->options(fn (): array => app(InstructorReportService::class)->competitionSeasonOptions())
            ->default(fn (): ?int => app(InstructorReportService::class)->currentCompetitionSeason()?->id)
            ->searchable()
            ->preload();
    }

    protected function dateRangeFilter(): Filter
    {
        return Filter::make('date_range')
            ->schema([
                DatePicker::make('from')
                    ->label('From')
                    ->default(fn (): string => app(InstructorReportService::class)->defaultDateFrom())
                    ->native(false),
                DatePicker::make('through')
                    ->label('Through')
                    ->default(fn (): string => app(InstructorReportService::class)->defaultDateThrough())
                    ->afterOrEqual('from')
                    ->native(false),
            ])
            ->columns(2)
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if (filled($data['from'] ?? null)) {
                    $indicators[] = 'From '.$data['from'];
                }

                if (filled($data['through'] ?? null)) {
                    $indicators[] = 'Through '.$data['through'];
                }

                return $indicators;
            });
    }
}
