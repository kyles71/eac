<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CompetitionSeasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }

    public static function components(): array
    {
        return [
            Section::make('Competition Season')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull(),
                    DatePicker::make('starts_on')
                        ->label('Starts On')
                        ->required(),
                    DatePicker::make('ends_on')
                        ->label('Ends On')
                        ->required()
                        ->afterOrEqual('starts_on'),
                ]),
        ];
    }
}
