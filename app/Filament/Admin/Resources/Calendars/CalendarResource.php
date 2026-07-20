<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Calendars;

use App\Filament\Admin\Resources\Calendars\Pages\ListCalendars;
use App\Filament\Admin\Resources\Calendars\Schemas\CalendarForm;
use App\Filament\Admin\Resources\Calendars\Tables\CalendarsTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Calendar;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class CalendarResource extends Resource
{
    protected static ?string $model = Calendar::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsCalendars;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CalendarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CalendarsTable::configure($table);
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
            'index' => ListCalendars::route('/'),
        ];
    }
}
