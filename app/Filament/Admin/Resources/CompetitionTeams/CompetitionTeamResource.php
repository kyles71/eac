<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams;

use App\Filament\Admin\Resources\CompetitionTeams\Pages\CreateCompetitionTeam;
use App\Filament\Admin\Resources\CompetitionTeams\Pages\EditCompetitionTeam;
use App\Filament\Admin\Resources\CompetitionTeams\Pages\ListCompetitionTeams;
use App\Filament\Admin\Resources\CompetitionTeams\Pages\ViewCompetitionTeam;
use App\Filament\Admin\Resources\CompetitionTeams\RelationManagers\StaffRelationManager;
use App\Filament\Admin\Resources\CompetitionTeams\RelationManagers\StudentsRelationManager;
use App\Filament\Admin\Resources\CompetitionTeams\Schemas\CompetitionTeamForm;
use App\Filament\Admin\Resources\CompetitionTeams\Schemas\CompetitionTeamInfolist;
use App\Filament\Admin\Resources\CompetitionTeams\Tables\CompetitionTeamsTable;
use App\Models\CompetitionTeam;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CompetitionTeamResource extends Resource
{
    protected static ?string $model = CompetitionTeam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static UnitEnum|string|null $navigationGroup = 'Competition';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CompetitionTeamForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompetitionTeamInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompetitionTeamsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StudentsRelationManager::class,
            StaffRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompetitionTeams::route('/'),
            'create' => CreateCompetitionTeam::route('/create'),
            'view' => ViewCompetitionTeam::route('/{record}'),
            'edit' => EditCompetitionTeam::route('/{record}/edit'),
        ];
    }
}
