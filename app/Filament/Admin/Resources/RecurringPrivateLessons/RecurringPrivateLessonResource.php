<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons;

use App\Filament\Admin\Resources\RecurringPrivateLessons\Pages\CreateRecurringPrivateLesson;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Pages\EditRecurringPrivateLesson;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Pages\ListRecurringPrivateLessons;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Pages\ViewRecurringPrivateLesson;
use App\Filament\Admin\Resources\RecurringPrivateLessons\RelationManagers\ChargesRelationManager;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Schemas\RecurringPrivateLessonForm;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Schemas\RecurringPrivateLessonInfolist;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Tables\RecurringPrivateLessonsTable;
use App\Models\RecurringPrivateLesson;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class RecurringPrivateLessonResource extends Resource
{
    protected static ?string $model = RecurringPrivateLesson::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::ClassesAndSchedule;

    protected static ?int $navigationSort = AdminNavigation::ScheduleRecurringPrivateLessons;

    protected static bool $isGloballySearchable = false;

    protected static ?string $navigationLabel = 'Recurring Private Lessons';

    protected static ?string $modelLabel = 'recurring private lesson';

    public static function form(Schema $schema): Schema
    {
        return RecurringPrivateLessonForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecurringPrivateLessonInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringPrivateLessonsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChargesRelationManager::class,
        ];
    }

    public static function getRecordTitle(?\Illuminate\Database\Eloquent\Model $record): string
    {
        if (! $record instanceof RecurringPrivateLesson) {
            return parent::getRecordTitle($record);
        }

        $record->loadMissing(['course', 'student']);

        return $record->course->name.' — '.$record->student->displayName();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['course.teachers', 'student', 'user']);
        $user = auth()->user();

        if ($user instanceof User && ! $user->hasAnyRole(['owner', 'super_admin'])) {
            $query->whereHas(
                'course.teachers',
                fn (Builder $query): Builder => $query->whereKey($user->id),
            );
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringPrivateLessons::route('/'),
            'create' => CreateRecurringPrivateLesson::route('/create'),
            'view' => ViewRecurringPrivateLesson::route('/{record}'),
            'edit' => EditRecurringPrivateLesson::route('/{record}/edit'),
        ];
    }
}
