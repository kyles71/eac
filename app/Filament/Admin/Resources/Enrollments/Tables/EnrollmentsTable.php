<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Enrollments\Tables;

use App\Filament\Admin\Resources\Students\Schemas\StudentForm;
use App\Models\Enrollment;
use App\Models\Student;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class EnrollmentsTable
{
    public static function configure(Table $table, bool $only_my_enrollments = false): Table
    {
        return $table
            ->query(fn () => Enrollment::query()
                ->when($only_my_enrollments, function ($query): void {
                    $query->where('user_id', auth()->id());
                })
            )
            ->recordTitle(fn ($record) => $record->course->name)
            ->columns([
                TextColumn::make('course.name')
                    ->searchable(),
                TextColumn::make('user.full_name')
                    ->label('User')
                    ->hidden($only_my_enrollments)
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('student.full_name')
                    ->label('Student')
                    ->searchable(['first_name', 'last_name']),
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
