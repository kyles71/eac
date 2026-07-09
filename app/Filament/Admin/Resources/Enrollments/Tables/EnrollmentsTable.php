<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Enrollments\Tables;

use App\Filament\Admin\Resources\Students\Schemas\StudentForm;
use App\Models\Enrollment;
use App\Models\Student;
use Carbon\CarbonInterface;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class EnrollmentsTable
{
    public static function configure(Table $table, bool $only_my_enrollments = false): Table
    {
        return $table
            ->query(fn () => Enrollment::query()
                ->with(['course.events', 'student', 'user'])
                ->when($only_my_enrollments, function ($query): void {
                    $query->where('user_id', auth()->id());
                })
            )
            ->recordTitle(fn ($record) => $record->course->name)
            ->columns([
                TextColumn::make('course.name')
                    ->label('Course')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('course.semester')
                    ->label('Semester')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('next_class')
                    ->label('Next Class')
                    ->state(fn (Enrollment $record): ?CarbonInterface => $record->course?->nextMeetingStartsAt())
                    ->dateTime()
                    ->placeholder('No upcoming class')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('user.full_name')
                    ->label('Parent / User')
                    ->hidden($only_my_enrollments)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('student.full_name')
                    ->label('Student')
                    ->placeholder('Needs student')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('assignment_status')
                    ->label('Assignment')
                    ->state(fn (Enrollment $record): string => $record->student_id === null ? 'Needs student' : 'Assigned')
                    ->badge()
                    ->color(fn (Enrollment $record): string => $record->student_id === null ? 'warning' : 'success')
                    ->searchable(false)
                    ->sortable(false),
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
                SelectFilter::make('assignment_status')
                    ->label('Assignment')
                    ->options([
                        'open' => 'Needs student',
                        'assigned' => 'Assigned',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('student_id'),
                        'assigned' => $query->whereNotNull('student_id'),
                        default => $query,
                    }),
                SelectFilter::make('course_id')
                    ->label('Course')
                    ->relationship('course', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label('Assign Student')
                    ->hidden(fn ($record) => $record->student_id)
                    ->schema([
                        Select::make('student_id')
                            ->required()
                            ->searchableRelationship(
                                name: 'student',
                                searchColumns: ['first_name', 'last_name'],
                                labelFromRecord: fn (Student $student): string => $student->fullName,
                                modifyQueryUsing: fn (Builder $query, Enrollment $record): Builder => $query->where('user_id', $record->user_id),
                                orderBy: ['first_name', 'last_name'],
                            )
                            ->createOptionForm(fn (Schema $schema, Enrollment $record): Schema => StudentForm::configure($schema, $record->user_id))
                            ->createOptionUsing(function (array $data, Enrollment $record): int {
                                return $record->user->students()->create($data)->getKey();
                            }),
                    ]),
            ]);
    }
}
