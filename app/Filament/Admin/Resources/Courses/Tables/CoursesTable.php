<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Tables;

use App\Filament\Actions\DeleteProductableAction;
use App\Filament\Actions\DeleteProductableBulkAction;
use App\Filament\Actions\SendEmailAction;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('product.price')
                    ->label('Price')
                    ->moneyCents()
                    ->placeholder('No product'),
                TextColumn::make('semester')
                    ->badge()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_capacity')
                    ->label('Available')
                    ->state(fn (Course $record): int => $record->getAvailableCapacity())
                    ->searchable(false)
                    ->sortable(false)
                    ->badge()
                    ->color(fn (Course $record): string => $record->getAvailableCapacity() > 0 ? 'success' : 'danger'),
                TextColumn::make('first_meeting_starts_at')
                    ->label('Starts At')
                    ->state(fn (Course $record): mixed => $record->firstMeetingStartsAt())
                    ->dateTime()
                    ->sortable(false),
                TextColumn::make('scheduled_duration')
                    ->label('Duration')
                    ->state(fn (Course $record): ?int => $record->scheduledDurationMinutes())
                    ->numeric()
                    ->sortable(false),
                TextColumn::make('guest_teacher')
                    ->searchable(),
                TextColumn::make('teacher_display_name')
                    ->label('Teachers')
                    ->searchable(false)
                    ->sortable(false),
                SpatieTagsColumn::make('tags')
                    ->label('Course Tags')
                    ->type(Course::GENERAL_TAG_TYPE)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                SendEmailAction::make()
                    ->to(fn (Course $record): array => self::emailRecipients($record)),
                DeleteProductableAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteProductableBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<int, Student|string>
     */
    private static function emailRecipients(Course $course): array
    {
        return Enrollment::query()
            ->where('course_id', $course->id)
            ->with(['student', 'user'])
            ->get()
            ->flatMap(function (Enrollment $enrollment): array {
                if ($enrollment->student === null) {
                    return [$enrollment->user->email];
                }

                if ($enrollment->student->user_id !== $enrollment->user_id) {
                    return [$enrollment->student, $enrollment->user->email];
                }

                return [$enrollment->student];
            })
            ->all();
    }
}
