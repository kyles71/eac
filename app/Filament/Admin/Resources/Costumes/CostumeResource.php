<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes;

use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Filament\Admin\Resources\Costumes\Pages\PurchaseStatus;
use App\Filament\Admin\Resources\Costumes\Pages\ViewCostume;
use App\Filament\Admin\Resources\Costumes\Schemas\CostumeForm;
use App\Filament\Admin\Resources\Costumes\Schemas\CostumeInfolist;
use App\Filament\Admin\Resources\Costumes\Tables\CostumesTable;
use App\Models\Costume;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CostumeResource extends Resource
{
    protected static ?string $model = Costume::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::Storefront;

    protected static ?int $navigationSort = AdminNavigation::StoreCostumes;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'vendor', 'vendor_number', 'course.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return CostumeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CostumeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostumesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostumes::route('/'),
            'view' => ViewCostume::route('/{record}'),
            'purchase-status' => PurchaseStatus::route('/{record}/purchase-status'),
        ];
    }
}
