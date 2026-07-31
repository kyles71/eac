<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Schemas;

use App\Filament\Shared\Schemas\CompetitionMembershipHistory;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Services\StudentProfileService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StudentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $profile = app(StudentProfileService::class);
        $medicalWaiver = fn (Student $student): ?StudentWaiver => $profile->medicalWaiver($student);

        return $schema
            ->components([
                Section::make('Student')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('nickname')
                            ->placeholder('-'),
                        TextEntry::make('birthdate')
                            ->date(),
                        TextEntry::make('user.full_name')
                            ->label('Parent / User')
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ]),
                Section::make('Tags')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        SpatieTagsEntry::make('tags')
                            ->label('Student Tags')
                            ->type(Student::GENERAL_TAG_TYPE),
                    ]),
                CompetitionMembershipHistory::make(),
                Section::make('Medical Waiver')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('medical_waiver_status')
                            ->label('Status')
                            ->state(fn (Student $record) => $record->medicalWaiverStatus())
                            ->badge(),
                        TextEntry::make('medical_release_consent')
                            ->label('Medical Release Consent')
                            ->state(fn (Student $record): string => self::consentLabel(
                                $medicalWaiver($record)?->medical_release_consent,
                            ))
                            ->badge()
                            ->color(fn (string $state): string => self::consentColor($state)),
                        TextEntry::make('medical_release_signed_on')
                            ->label('Medical Release Signed On')
                            ->state(fn (Student $record) => $medicalWaiver($record)?->medical_release_signed_on)
                            ->date()
                            ->placeholder('Not provided'),
                        TextEntry::make('waiver_allergies')
                            ->label('Allergies')
                            ->state(fn (Student $record): ?string => $medicalWaiver($record)?->allergies)
                            ->placeholder('None reported')
                            ->columnSpanFull(),
                        TextEntry::make('waiver_medical_conditions')
                            ->label('Medical Conditions')
                            ->state(fn (Student $record): ?string => $medicalWaiver($record)?->medical_conditions)
                            ->placeholder('None reported')
                            ->columnSpanFull(),
                        TextEntry::make('waiver_past_injuries')
                            ->label('Past Injuries')
                            ->state(fn (Student $record): ?string => $medicalWaiver($record)?->past_injuries)
                            ->placeholder('None reported')
                            ->columnSpanFull(),
                        TextEntry::make('waiver_medications')
                            ->label('Medications')
                            ->state(fn (Student $record): ?string => $medicalWaiver($record)?->medications)
                            ->placeholder('None reported')
                            ->columnSpanFull(),
                        TextEntry::make('waiver_behavioral_notes')
                            ->label('Behavioral / Social-Emotional Notes')
                            ->state(fn (Student $record): ?string => $medicalWaiver($record)?->behavioral_notes)
                            ->placeholder('None reported')
                            ->columnSpanFull(),
                        TextEntry::make('media_release_consent')
                            ->label('Media Release Consent')
                            ->state(fn (Student $record): string => self::consentLabel(
                                $medicalWaiver($record)?->media_release_consent,
                            ))
                            ->badge()
                            ->color(fn (string $state): string => self::consentColor($state)),
                        TextEntry::make('media_release_signed_on')
                            ->label('Media Release Signed On')
                            ->state(fn (Student $record) => $medicalWaiver($record)?->media_release_signed_on)
                            ->date()
                            ->placeholder('Not provided'),
                    ]),
                Section::make('Attendance Totals by Course')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('attendance_totals')
                            ->hiddenLabel()
                            ->state(fn (Student $record): array => $profile->attendanceTotals($record))
                            ->table([
                                TableColumn::make('Course'),
                                TableColumn::make('Present'),
                                TableColumn::make('Late'),
                                TableColumn::make('Excused absence'),
                                TableColumn::make('Unexcused absence'),
                                TableColumn::make('Not recorded'),
                                TableColumn::make('Total events'),
                            ])
                            ->schema([
                                TextEntry::make('course'),
                                TextEntry::make('present'),
                                TextEntry::make('late'),
                                TextEntry::make('excused_absence'),
                                TextEntry::make('unexcused_absence'),
                                TextEntry::make('not_recorded'),
                                TextEntry::make('total_events'),
                            ])
                            ->contained(false)
                            ->placeholder('No course attendance is available.'),
                    ]),
                Section::make('Event Attendance Notes')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('attendance_notes')
                            ->hiddenLabel()
                            ->state(fn (Student $record): array => $profile->attendanceNotes($record))
                            ->table([
                                TableColumn::make('Event'),
                                TableColumn::make('Starts at'),
                                TableColumn::make('Note'),
                            ])
                            ->schema([
                                TextEntry::make('event'),
                                TextEntry::make('starts_at')
                                    ->dateTime(
                                        'M j, Y g:i A',
                                        (string) config('app.display_timezone', config('app.timezone')),
                                    )
                                    ->placeholder('Not scheduled'),
                                TextEntry::make('note'),
                            ])
                            ->contained(false)
                            ->placeholder('No event attendance notes are available.'),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }

    private static function consentLabel(?bool $consent): string
    {
        return match ($consent) {
            true => 'Agreed',
            false => 'Declined',
            null => 'Not provided',
        };
    }

    private static function consentColor(string $state): string
    {
        return match ($state) {
            'Agreed' => 'success',
            'Declined' => 'danger',
            default => 'gray',
        };
    }
}
