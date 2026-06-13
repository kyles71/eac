<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Schemas;

use App\Models\CompetitionTeam;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CompetitionTeamInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Competition Team')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('season.name')
                            ->label('Season'),
                        TextEntry::make('season.starts_on')
                            ->label('Starts On')
                            ->date(),
                        TextEntry::make('season.ends_on')
                            ->label('Ends On')
                            ->date(),
                        TextEntry::make('status')
                            ->state(fn (CompetitionTeam $record): string => $record->season->status())
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
