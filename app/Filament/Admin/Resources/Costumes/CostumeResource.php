<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes;

use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Filament\Admin\Resources\Costumes\Schemas\CostumeForm;
use App\Filament\Admin\Resources\Costumes\Tables\CostumesTable;
use App\Models\Costume;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class CostumeResource extends Resource
{
    protected static ?string $model = Costume::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return CostumeForm::configure($schema);
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
        ];
    }
}
