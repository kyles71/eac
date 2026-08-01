<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Models\CompetitionTeam;
use App\Models\Student;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

final class CompetitionMembershipHistory
{
    public static function make(): Section
    {
        return Section::make('Competition Membership')
            ->columnSpanFull()
            ->collapsed()
            ->visible(fn (Student|User|null $record): bool => $record?->competitionTeams()->exists() ?? false)
            ->schema([
                RepeatableEntry::make('competitionTeams')
                    ->label('')
                    ->table([
                        TableColumn::make('Season'),
                        TableColumn::make('Team'),
                        TableColumn::make('Status'),
                    ])
                    ->schema([
                        TextEntry::make('season.name')
                            ->label('Season'),
                        TextEntry::make('name')
                            ->label('Team'),
                        TextEntry::make('season_status')
                            ->label('Status')
                            ->state(fn (CompetitionTeam $record): string => $record->season->status())
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Current' => 'success',
                                'Upcoming' => 'info',
                                default => 'gray',
                            }),
                    ])
                    ->contained(false),
            ]);
    }
}
