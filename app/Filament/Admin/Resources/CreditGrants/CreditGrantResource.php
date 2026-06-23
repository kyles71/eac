<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants;

use App\Filament\Admin\Resources\CreditGrants\Pages\CreateCreditGrant;
use App\Filament\Admin\Resources\CreditGrants\Pages\ListCreditGrants;
use App\Filament\Admin\Resources\CreditGrants\Pages\ViewCreditGrant;
use App\Filament\Admin\Resources\CreditGrants\Schemas\CreditGrantForm;
use App\Filament\Admin\Resources\CreditGrants\Schemas\CreditGrantInfolist;
use App\Filament\Admin\Resources\CreditGrants\Tables\CreditGrantsTable;
use App\Filament\Admin\Resources\CreditGrants\Widgets\CreditGrantStats;
use App\Models\CreditGrant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class CreditGrantResource extends Resource
{
    protected static ?string $model = CreditGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Purchases';

    protected static ?string $modelLabel = 'Store Credit Grant';

    protected static ?string $pluralModelLabel = 'Store Credit';

    protected static ?string $recordTitleAttribute = 'description';

    public static function form(Schema $schema): Schema
    {
        return CreditGrantForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CreditGrantInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CreditGrantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getWidgets(): array
    {
        return [
            CreditGrantStats::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditGrants::route('/'),
            'create' => CreateCreditGrant::route('/create'),
            'view' => ViewCreditGrant::route('/{record}'),
        ];
    }
}
