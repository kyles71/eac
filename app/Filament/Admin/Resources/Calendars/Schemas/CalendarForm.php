<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Calendars\Schemas;

use App\Enums\CalendarAccess;
use App\Filament\Shared\Schemas\PeopleAndGroupsPicker;
use App\Models\Calendar;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Calendar')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        ColorPicker::make('background_color')
                            ->label('Background Color')
                            ->regex('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})\b$/'),
                        Select::make('access')
                            ->label('Availability')
                            ->options(CalendarAccess::class)
                            ->default(CalendarAccess::Public->value)
                            ->required(fn (?Calendar $record): bool => ! ($record?->isSystemCalendar() ?? false))
                            ->live()
                            ->dehydrated(fn (?Calendar $record): bool => ! ($record?->isSystemCalendar() ?? false))
                            ->hidden(fn (?Calendar $record): bool => $record?->isSystemCalendar() ?? false)
                            ->columnSpanFull(),
                    ]),
                PeopleAndGroupsPicker::calendarAudiences(),
            ]);
    }
}
