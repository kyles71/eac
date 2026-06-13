<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons;

use App\Filament\Admin\Resources\CompetitionSeasons\Pages\CreateCompetitionSeason;
use App\Filament\Admin\Resources\CompetitionSeasons\Pages\EditCompetitionSeason;
use App\Filament\Admin\Resources\CompetitionSeasons\Pages\ListCompetitionSeasons;
use App\Filament\Admin\Resources\CompetitionSeasons\Pages\ViewCompetitionSeason;
use App\Filament\Admin\Resources\CompetitionSeasons\RelationManagers\TeamsRelationManager;
use App\Filament\Admin\Resources\CompetitionSeasons\Schemas\CompetitionSeasonForm;
use App\Filament\Admin\Resources\CompetitionSeasons\Schemas\CompetitionSeasonInfolist;
use App\Filament\Admin\Resources\CompetitionSeasons\Tables\CompetitionSeasonsTable;
use App\Models\CompetitionSeason;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CompetitionSeasonResource extends Resource
{
    protected static ?string $model = CompetitionSeason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static UnitEnum|string|null $navigationGroup = 'Competition';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CompetitionSeasonForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompetitionSeasonInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompetitionSeasonsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TeamsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompetitionSeasons::route('/'),
            'create' => CreateCompetitionSeason::route('/create'),
            'view' => ViewCompetitionSeason::route('/{record}'),
            'edit' => EditCompetitionSeason::route('/{record}/edit'),
        ];
    }
}
