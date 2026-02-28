<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DiscountCodes;

use App\Filament\Admin\Resources\DiscountCodes\Pages\ListDiscountCodes;
use App\Filament\Admin\Resources\DiscountCodes\Schemas\DiscountCodeForm;
use App\Filament\Admin\Resources\DiscountCodes\Tables\DiscountCodesTable;
use App\Models\DiscountCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class DiscountCodeResource extends Resource
{
    protected static ?string $model = DiscountCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static UnitEnum|string|null $navigationGroup = 'Store';

    protected static ?string $recordTitleAttribute = 'code';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'code',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return DiscountCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DiscountCodesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscountCodes::route('/'),
        ];
    }
}
