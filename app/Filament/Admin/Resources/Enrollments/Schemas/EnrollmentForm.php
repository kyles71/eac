<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Enrollments\Schemas;

use App\Filament\Admin\Resources\Courses\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Admin\Resources\Students\Schemas\StudentForm;
use App\Models\Student;
use App\Models\User;
use App\Support\Filament\HouseholdStudentSelect;
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
                            ->relationship('course', 'name', function (Builder $query): void {
                                $user = auth()->user();

                                if ($user instanceof User && ! $user->hasAnyRole(['owner', 'super_admin'])) {
                                    $query->where(function (Builder $query) use ($user): void {
                                        $query
                                            ->where('is_private', false)
                                            ->orWhereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id));
                                    });
                                }
                            })
                            ->selectablePlaceholder(false)
                            ->required(),
                        HouseholdStudentSelect::user()
                            ->label('Parent / User')
                            ->selectablePlaceholder(false)
                            ->required(),
                    ]),
                Section::make('Student Assignment')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        HouseholdStudentSelect::student()
                            ->label('Student')
                            ->helperText('Only students belonging to the selected parent / user are shown.')
                            ->createOptionForm(fn (Schema $schema, Get $get): Schema => StudentForm::configure($schema, $get('user_id')))
                            ->createOptionUsing(function (array $data, Get $get): int {
                                $data['user_id'] = $get('user_id');

                                return Student::create($data)->getKey();
                            }),
                    ]),
            ]);
    }
}
