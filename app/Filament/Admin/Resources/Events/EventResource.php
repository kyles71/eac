<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events;

use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Events\Schemas\EventInfolist;
use App\Filament\Admin\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::ClassesAndSchedule;

    protected static ?int $navigationSort = AdminNavigation::ScheduleEvents;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'view' => ViewEvent::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user instanceof User && ! $user->hasAnyRole(['owner', 'super_admin'])) {
            $query->where(function (Builder $query) use ($user): void {
                $query
                    ->whereNull('course_id')
                    ->orWhereHas('course', fn (Builder $query): Builder => $query
                        ->where('is_private', false)
                        ->orWhereHas('teachers', fn (Builder $query): Builder => $query->whereKey($user->id)));
            });
        }

        return $query;
    }
}
