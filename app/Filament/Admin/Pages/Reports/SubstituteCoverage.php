<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Reports;

use App\Enums\ReportKey;
use App\Services\Reports\InstructorReportService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SubstituteCoverage extends InstructorReportPage
{
    protected static ?string $title = 'Substitute Coverage';

    protected static ?string $slug = 'reports/instructor/substitute-coverage';

    public function table(Table $table): Table
    {
        return $this->configureReportTable(
            $table
                ->columns([
                    TextColumn::make('date')->sortable()->toggleable(),
                    TextColumn::make('time')->toggleable(),
                    TextColumn::make('course_name')->label('Class')->searchable()->sortable()->toggleable(),
                    TextColumn::make('academic_term')->label('Academic Term')->sortable()->toggleable(),
                    TextColumn::make('assigned_instructors')->label('Assigned Instructor(s)')->searchable()->toggleable(),
                    TextColumn::make('reason')->searchable()->wrap()->toggleable(),
                    TextColumn::make('confirmed_substitute')->label('Confirmed Substitute')->searchable()->toggleable(),
                    TextColumn::make('coverage_status')
                        ->label('Coverage Status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'Confirmed' => 'success',
                            'Awaiting Response', 'Replacement Pending' => 'warning',
                            default => 'danger',
                        })
                        ->sortable()
                        ->toggleable(),
                    TextColumn::make('hours')->numeric(decimalPlaces: 2)->sortable()->toggleable(),
                ])
                ->filters([
                    $this->academicTermFilter(),
                    $this->instructorFilter(),
                    $this->dateRangeFilter(),
                    SelectFilter::make('coverage_status')
                        ->label('Coverage Status')
                        ->options(fn (): array => app(InstructorReportService::class)
                            ->substituteCoverageStatusOptions()),
                ])
                ->defaultSort('date'),
        );
    }

    protected static function getReportKey(): ReportKey
    {
        return ReportKey::SubstituteCoverage;
    }
}
