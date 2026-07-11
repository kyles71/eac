<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Enrollments\Schemas;

use App\Filament\Admin\Resources\Courses\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Admin\Resources\Students\Schemas\StudentForm;
use App\Models\Student;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class EnrollmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Enrollment')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('course_id')
                            ->label('Course')
                            ->hidden(fn ($livewire): bool => $livewire instanceof EnrollmentsRelationManager)
                            ->relationship('course', 'name')
                            ->required(),
                        Select::make('user_id')
                            ->label('Parent / User')
                            ->live()
                            ->required()
                            ->userRelationship(
                                'user',
                                fn (Builder $query, Get $get): Builder => $query->when($get('student_id'), function (Builder $query) use ($get): void {
                                    $query->select('users.*')
                                        ->join('students', 'students.user_id', '=', 'users.id')
                                        ->where('students.id', $get('student_id'));
                                }),
                            ),
                    ]),
                Section::make('Student Assignment')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('student_id')
                            ->label('Student')
                            ->helperText('Only students belonging to the selected parent / user are shown.')
                            ->live()
                            ->required()
                            ->studentRelationship(
                                'student',
                                fn (Builder $query, Get $get): Builder => $query->when($get('user_id'), fn (Builder $query, mixed $userId): Builder => $query->where('user_id', $userId)),
                            )
                            ->createOptionForm(fn (Schema $schema, Get $get): Schema => StudentForm::configure($schema, $get('user_id')))
                            ->createOptionUsing(function (array $data, Get $get): int {
                                $data['user_id'] = $get('user_id');

                                return Student::create($data)->getKey();
                            }),
                    ]),
            ]);
    }
}
