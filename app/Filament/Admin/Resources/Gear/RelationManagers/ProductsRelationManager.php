<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gear\RelationManagers;

use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Products\Schemas\ProductForm;
use App\Filament\Admin\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Services\GearPurchaseReportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $relatedResource = ProductResource::class;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema, includeLinkedItem: false);
    }

    public function table(Table $table): Table
    {
        return ProductsTable::configure($table)
            ->headerActions([
                CreateAction::make()
                    ->label('Create Product Listing')
                    ->mutateDataUsing(fn (array $data): array => ProductResource::normalizePricingData($data)),
            ])
            ->recordUrl(fn (Product $record): string => ProductResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->mutateDataUsing(fn (array $data): array => ProductResource::normalizePricingData($data)),
                    Action::make('downloadPurchaseReport')
                        ->label('Download Purchase Report')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn (Product $record) => app(GearPurchaseReportService::class)->downloadForProduct($record)),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
