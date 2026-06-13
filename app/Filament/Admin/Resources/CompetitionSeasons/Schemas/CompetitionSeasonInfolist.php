<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\Schemas;

use App\Models\CompetitionSeason;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CompetitionSeasonInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Competition Season')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->columnSpanFull(),
                        TextEntry::make('starts_on')
                            ->label('Starts On')
                            ->date(),
                        TextEntry::make('ends_on')
                            ->label('Ends On')
                            ->date(),
                        TextEntry::make('status')
                            ->state(fn (CompetitionSeason $record): string => $record->status())
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Current' => 'success',
                                'Upcoming' => 'info',
                                default => 'gray',
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
