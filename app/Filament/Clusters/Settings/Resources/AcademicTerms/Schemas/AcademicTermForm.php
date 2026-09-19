<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\AcademicTerms\Schemas;

use App\Enums\CourseSemester;
use App\Models\AcademicTerm;
use App\Services\AcademicTermService;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class AcademicTermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Academic Term')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('semester')
                            ->options(CourseSemester::class)
                            ->enum(CourseSemester::class)
                            ->disabledOn('edit')
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('year')
                            ->label('Calendar Year')
                            ->numeric()
                            ->minValue(2000)
                            ->maxValue(9999)
                            ->disabledOn('edit')
                            ->required(),
                        Toggle::make('uses_default_dates')
                            ->label('Use Recurring Default Dates')
                            ->helperText('Upcoming terms follow the recurring defaults. Dates are preserved once a term begins. Turn this off to override the dates for this term only.')
                            ->rules([
                                fn (Get $get, ?AcademicTerm $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                    if (! $value || ($record?->uses_default_dates && ! $record->isUpcoming())) {
                                        return;
                                    }

                                    $semesterValue = $get('semester') ?? $record?->semester;
                                    $semester = $semesterValue instanceof CourseSemester
                                        ? $semesterValue
                                        : (is_string($semesterValue) ? CourseSemester::tryFrom($semesterValue) : null);
                                    $year = filter_var($get('year') ?? $record?->year, FILTER_VALIDATE_INT);

                                    if (! $semester instanceof CourseSemester || $year === false) {
                                        return;
                                    }

                                    $overlappingTerm = app(AcademicTermService::class)
                                        ->findOverlappingTermForDefaultDates($semester, $year, $record);

                                    if (! $overlappingTerm instanceof AcademicTerm) {
                                        return;
                                    }

                                    $fail(sprintf(
                                        'The recurring default dates overlap %s (%s–%s). Turn off recurring defaults or adjust the overlapping term first.',
                                        $overlappingTerm->display_name,
                                        $overlappingTerm->starts_on->format('M j, Y'),
                                        $overlappingTerm->ends_on->format('M j, Y'),
                                    ));
                                },
                            ])
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),
                        DatePicker::make('starts_on')
                            ->label('Starts On')
                            ->required(fn (Get $get): bool => ! $get('uses_default_dates'))
                            ->visible(fn (Get $get): bool => ! $get('uses_default_dates')),
                        DatePicker::make('ends_on')
                            ->label('Ends On')
                            ->afterOrEqual('starts_on')
                            ->required(fn (Get $get): bool => ! $get('uses_default_dates'))
                            ->visible(fn (Get $get): bool => ! $get('uses_default_dates')),
                        TextInput::make('target_enrollments')
                            ->label('Target Enrollment Goal')
                            ->helperText('Used by the Enrollment Reports dashboard for this term.')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('stretch_goal_enrollments')
                            ->label('Stretch Enrollment Goal')
                            ->helperText('Must be at least the target goal when both are set.')
                            ->numeric()
                            ->minValue(0)
                            ->gte('target_enrollments'),
                    ]),
            ]);
    }
}
