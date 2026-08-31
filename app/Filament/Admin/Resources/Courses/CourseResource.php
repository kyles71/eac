<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses;

use App\Filament\Admin\Resources\Courses\Pages\CourseAttendance;
use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Courses\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Admin\Resources\Courses\RelationManagers\EventsRelationManager;
use App\Filament\Admin\Resources\Courses\Schemas\CourseForm;
use App\Filament\Admin\Resources\Courses\Schemas\CourseInfolist;
use App\Filament\Admin\Resources\Courses\Tables\CoursesTable;
use App\Models\Course;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::ClassesAndSchedule;

    protected static ?int $navigationSort = AdminNavigation::ScheduleCourses;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return CourseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CourseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CoursesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EnrollmentsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'view' => ViewCourse::route('/{record}'),
            'attendance' => CourseAttendance::route('/{record}/attendance'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user instanceof User && ! $user->hasAnyRole(['owner', 'super_admin'])) {
            $query->where(function (Builder $query) use ($user): void {
                $query
                    ->where('is_private', false)
                    ->orWhereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id));
            });
        }

        return $query;
    }
}
