<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Models\Enrollment;
use App\Support\EnrollmentStatus;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

final class ViewCourseDetailsAction
{
    public static function make(): ViewAction
    {
        return ViewAction::make('viewCourseDetails')
            ->label('View Details')
            ->icon(Heroicon::OutlinedAcademicCap)
            ->modalHeading(fn (Enrollment $record): string => $record->course?->name ?? 'Class Details')
            ->modalWidth('lg')
            ->slideOver(false)
            ->schema(self::schema())
            ->fillForm(fn (Enrollment $record): array => self::data($record));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(Enrollment $enrollment): array
    {
        $course = $enrollment->course;

        return [
            'name' => $course?->name,
            'semester' => $course?->semester?->getLabel(),
            'teacher' => $course?->teacherDisplayName,
            'student' => $enrollment->student?->fullName,
            'starts_at' => $course?->start_time,
            'duration' => $course?->duration !== null ? "{$course->duration} minutes" : null,
            'meetings' => $course?->events->count(),
            'status' => EnrollmentStatus::for($enrollment),
            'description' => $course?->description,
        ];
    }

    /**
     * @return array<Section>
     */
    private static function schema(): array
    {
        return [
            Section::make('Course')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Course'),
                    TextInput::make('semester'),
                    TextInput::make('teacher'),
                    TextInput::make('student')
                        ->placeholder('Unassigned'),
                    DateTimePicker::make('starts_at')
                        ->label('Starts At')
                        ->timezone((string) config('app.display_timezone', config('app.timezone'))),
                    TextInput::make('duration'),
                    TextInput::make('meetings')
                        ->label('Class Meetings'),
                    TextInput::make('status'),
                    Textarea::make('description')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
