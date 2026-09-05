<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Models\Student;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class HouseholdStudentSelect
{
    public static function user(
        string $statePath = 'user_id',
        string $relationshipName = 'user',
        string $studentStatePath = 'student_id',
    ): Select {
        return Select::make($statePath)
            ->userRelationship($relationshipName)
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($studentStatePath): void {
                $studentId = $get($studentStatePath);

                if (blank($studentId)) {
                    return;
                }

                $studentUserId = Student::query()
                    ->whereKey($studentId)
                    ->value('user_id');

                if ((string) $studentUserId !== (string) $state) {
                    $set($studentStatePath, null);
                }
            });
    }

    public static function student(
        string $statePath = 'student_id',
        string $relationshipName = 'student',
        string $userStatePath = 'user_id',
    ): Select {
        return Select::make($statePath)
            ->studentRelationship(
                $relationshipName,
                function (Builder $query, Get $get) use ($userStatePath): Builder {
                    $userId = $get($userStatePath);

                    return $query->when(
                        filled($userId),
                        fn (Builder $query): Builder => $query->where('user_id', $userId),
                    );
                },
            )
            ->preload()
            ->rule(fn (Get $get): Exists => Rule::exists(Student::class, 'id')
                ->where('user_id', $get($userStatePath)))
            ->live()
            ->afterStateUpdated(function (Set $set, mixed $state) use ($userStatePath): void {
                if (blank($state)) {
                    return;
                }

                $userId = Student::query()
                    ->whereKey($state)
                    ->value('user_id');

                if ($userId !== null) {
                    $set($userStatePath, $userId);
                }
            });
    }
}
