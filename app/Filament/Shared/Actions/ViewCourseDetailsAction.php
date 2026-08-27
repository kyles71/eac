<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Models\Enrollment;
use App\Support\EnrollmentStatus;
use App\Support\Filament\CourseStaffPresenter;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

final class ViewCourseDetailsAction
{
    public static function make(): ViewAction
    {
        return ViewAction::make('viewCourseDetails')
            ->label('View Details')
            ->icon(Heroicon::OutlinedAcademicCap)
            ->modalHeading(fn (Enrollment $record): string => $record->course->name)
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
            'semester' => $course?->academicTerm?->display_name,
            'teacher' => $course?->teacherDisplayName,
            'student' => $enrollment->student?->fullName,
            'starts_at' => $course?->firstMeetingStartsAt(),
            'duration' => ($duration = $course?->scheduledDurationMinutes()) !== null ? "{$duration} minutes" : null,
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
                    TextEntry::make('teacher')
                        ->formatStateUsing(fn (Enrollment $record): ?HtmlString => CourseStaffPresenter::render($record->course))
                        ->placeholder('-'),
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
