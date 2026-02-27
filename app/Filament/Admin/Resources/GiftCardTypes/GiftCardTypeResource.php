<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCardTypes;

use App\Filament\Admin\Resources\GiftCardTypes\Pages\CreateGiftCardType;
use App\Filament\Admin\Resources\GiftCardTypes\Pages\EditGiftCardType;
use App\Filament\Admin\Resources\GiftCardTypes\Pages\ListGiftCardTypes;
use App\Filament\Admin\Resources\GiftCardTypes\Schemas\GiftCardTypeForm;
use App\Filament\Admin\Resources\GiftCardTypes\Tables\GiftCardTypesTable;
use App\Models\GiftCardType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class GiftCardTypeResource extends Resource
{
    protected static ?string $model = GiftCardType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static UnitEnum|string|null $navigationGroup = 'Store';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Gift Card Types';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return GiftCardTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GiftCardTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGiftCardTypes::route('/'),
            'create' => CreateGiftCardType::route('/create'),
            'edit' => EditGiftCardType::route('/{record}/edit'),
        ];
    }
}
