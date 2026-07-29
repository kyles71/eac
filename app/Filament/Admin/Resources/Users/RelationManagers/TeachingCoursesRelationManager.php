<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Models\Course;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class TeachingCoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'teachingCourses';

    protected static ?string $relatedResource = CourseResource::class;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof User
            && $ownerRecord->hasRole('teacher')
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Teaching Courses')
            ->filters([
                SelectFilter::make('course_status')
                    ->label('Status')
                    ->default('active')
                    ->options([
                        'active' => 'Active',
                        'concluded' => 'Concluded',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filterCoursesByStatus($query, $data)),
            ])
            ->defaultSort('name')
            ->recordActions([])
            ->toolbarActions([])
            ->headerActions([]);
    }

    /**
     * @param  Builder<Course>  $query
     * @param  array{value?: string|null}  $data
     * @return Builder<Course>
     */
    private static function filterCoursesByStatus(Builder $query, array $data): Builder
    {
        return match ($data['value'] ?? null) {
            'active' => $query->notConcluded(),
            'concluded' => $query->concluded(),
            default => $query,
        };
    }
}
