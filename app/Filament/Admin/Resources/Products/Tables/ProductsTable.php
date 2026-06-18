<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Tables;

use App\Enums\ProductAvailabilityStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Services\ProductAvailabilityService;
use App\Support\MediaDisks;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->collection('images')
                    ->disk(MediaDisks::public())
                    ->visibility('public')
                    // ->conversion('thumb')
                    ->circular(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->moneyCents()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('availability_status')
                    ->label('Availability')
                    ->state(fn (Product $record): ProductAvailabilityStatus => $record->availabilityStatus())
                    ->badge()
                    ->formatStateUsing(fn (ProductAvailabilityStatus $state): string => $state->getLabel())
                    ->color(fn (ProductAvailabilityStatus $state): string => $state->getColor())
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('productable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => ProductType::labelForProductableType($state)),
                TextColumn::make('available_from')
                    ->dateTime()
                    ->placeholder('Immediately')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('available_until')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
                SelectFilter::make('availability_status')
                    ->label('Availability')
                    ->options(ProductAvailabilityStatus::adminOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $status = ProductAvailabilityStatus::tryFrom((string) ($data['value'] ?? ''));

                        if (! $status instanceof ProductAvailabilityStatus) {
                            return $query;
                        }

                        return app(ProductAvailabilityService::class)->applyAdminStatusFilter($query, $status);
                    }),
            ])
            ->recordActions([

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
