<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products;

use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Products\Pages\PurchaseStatus;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Filament\Admin\Resources\Products\Schemas\ProductForm;
use App\Filament\Admin\Resources\Products\Schemas\ProductInfolist;
use App\Filament\Admin\Resources\Products\Tables\ProductsTable;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\RecurringPrivateLessonCharge;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static UnitEnum|string|null $navigationGroup = AdminNavigation::Storefront;

    protected static ?int $navigationSort = AdminNavigation::StoreProducts;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizePricingData(array $data): array
    {
        if (($data['productable_type'] ?? null) !== GiftCardType::class || blank($data['productable_id'] ?? null)) {
            return $data;
        }

        $giftCardType = GiftCardType::query()->find($data['productable_id']);

        if (! $giftCardType instanceof GiftCardType) {
            return $data;
        }

        if ($giftCardType->allows_custom_amount) {
            $data['price'] = null;

            return $data;
        }

        if (blank($data['price'] ?? null)) {
            $data['price'] = $giftCardType->denomination;
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
            'purchase-status' => PurchaseStatus::route('/{record}/purchase-status'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('productable_type')
                    ->orWhere('productable_type', '!=', RecurringPrivateLessonCharge::class);
            });
    }
}
