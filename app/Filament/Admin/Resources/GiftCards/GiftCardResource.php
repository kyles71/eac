<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards;

use App\Filament\Admin\Resources\GiftCards\Pages\CreateGiftCard;
use App\Filament\Admin\Resources\GiftCards\Pages\EditGiftCard;
use App\Filament\Admin\Resources\GiftCards\Pages\ListGiftCards;
use App\Filament\Admin\Resources\GiftCards\Schemas\GiftCardForm;
use App\Filament\Admin\Resources\GiftCards\Tables\GiftCardsTable;
use App\Models\GiftCard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class GiftCardResource extends Resource
{
    protected static ?string $model = GiftCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

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
        return GiftCardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GiftCardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGiftCards::route('/'),
            'create' => CreateGiftCard::route('/create'),
            'edit' => EditGiftCard::route('/{record}/edit'),
        ];
    }
}
