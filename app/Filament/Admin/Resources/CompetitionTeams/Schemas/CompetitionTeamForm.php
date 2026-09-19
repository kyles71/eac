<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Schemas;

use App\Filament\Admin\Resources\CompetitionSeasons\RelationManagers\TeamsRelationManager;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

final class CompetitionTeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }

    public static function components(): array
    {
        return [
            Section::make('Competition Team')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('competition_season_id')
                        ->label('Season')
                        ->relationship(
                            'season',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->whereDate('ends_on', '>=', CompetitionSeason::comparisonDate())
                                ->orderByDesc('starts_on'),
                        )
                        ->hidden(fn ($livewire): bool => $livewire instanceof TeamsRelationManager)
                        ->selectablePlaceholder(false)
                        ->required(),
                    self::nameField(),
                ]),
        ];
    }

    public static function nameField(?int $seasonId = null): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            ->unique(
                ignoreRecord: true,
                modifyRuleUsing: fn (Unique $rule, Get $get, ?CompetitionTeam $record): Unique => $rule
                    ->where('competition_season_id', $seasonId ?? $get('competition_season_id') ?? $record?->competition_season_id),
            );
    }
}
