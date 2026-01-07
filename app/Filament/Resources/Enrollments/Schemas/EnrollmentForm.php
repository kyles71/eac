<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Filament\Resources\Courses\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Students\Schemas\StudentForm;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EnrollmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('course_id')
                    ->hidden(fn ( $livewire) => $livewire instanceof EnrollmentsRelationManager)
                    ->relationship('course', 'name')
                    ->required(),
                Select::make('user_id')
                    ->relationship('user', 'id', fn (Builder $query, Get $get) => $query->when($get('student_id'), function($q) use ($get) {
                        $q->select('users.*')
                            ->join('students', 'students.user_id', '=', 'users.id')
                            ->where('students.id', $get('student_id'));
                    })->orderBy('first_name')->orderBy('last_name'))
                    ->getOptionLabelFromRecordUsing(fn (User $user) => $user->fullName)
                    ->live()
                    ->required(),
                Select::make('student_id')
                    ->live()
                    ->required()
                    ->relationship('student', 'id', function(Builder $query, Get $get) {
                        return $query->when($get('user_id'), fn ($q) => $q->where('user_id', $get('user_id'))->orderBy('first_name')->orderBy('last_name'));
                    })
                    ->getOptionLabelFromRecordUsing(fn (Student $student) => $student->fullName)
                    ->createOptionForm(fn (Schema $schema, Get $get) => StudentForm::configure($schema, $get('user_id')))
                    ->createOptionUsing(function (array $data, Get $get): int {
                        // should validate user_id
                        $data['user_id'] = $get('user_id');
                        // will this assign to the correct user if being impersonated?
                        return Student::create($data)->getKey();
                    }),
            ]);
    }
}
