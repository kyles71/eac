<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gear;

use App\Filament\Admin\Resources\Gear\Pages\ListGear;
use App\Filament\Admin\Resources\Gear\Schemas\GearForm;
use App\Filament\Admin\Resources\Gear\Tables\GearTable;
use App\Models\Gear;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class GearResource extends Resource
{
    protected static ?string $model = Gear::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::Storefront;

    protected static ?int $navigationSort = AdminNavigation::StoreGear;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $pluralModelLabel = 'Gear';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return GearForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GearTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGear::route('/'),
        ];
    }
}
