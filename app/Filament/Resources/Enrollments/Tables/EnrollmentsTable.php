<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Filament\Resources\Students\Schemas\StudentForm;
use App\Models\Enrollment;
use App\Models\Student;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnrollmentsTable
{
    public static function configure(Table $table, bool $only_my_enrollments = false): Table
    {
        return $table
            ->query(fn () => Enrollment::query()
                ->when($only_my_enrollments, function ($query) {
                    $query->where('user_id', auth()->id());
                })
            )
            ->recordTitle(fn ($record) => $record->course->name)
            ->columns([
                TextColumn::make('course.name')
                    ->searchable(),
                TextColumn::make('user.fullName')
                    ->hidden($only_my_enrollments)
                    ->searchable(),
                TextColumn::make('student.fullName')
                    ->searchable(),
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
                            ->relationship('student', 'id', function(Builder $query, Enrollment $record) {
                                return $query->where('user_id', $record->user_id);
                                // return $query->where('user_id', auth()->id());
                            })
                            ->getOptionLabelFromRecordUsing(fn (Student $student) => $student->fullName)
                            ->createOptionForm(fn (Schema $schema, Enrollment $record) => StudentForm::configure($schema, $record->user_id))
                            // ->createOptionForm(fn (Schema $schema) => StudentForm::configure($schema, auth()->id()))
                            ->createOptionUsing(function (array $data, Enrollment $record): int {
                                return $record->user->students()->create($data)->getKey();
                                // will this assign to the correct user if being impersonated?
                                // return auth()->user()->students()->create($data)->getKey();
                            }),
                        ]),
                ]);
    }
}
