<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\AddToCart;
use App\Contracts\HasCapacity;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

final class Store extends TablePage
{
    protected static ?string $title = 'Store';

    protected static ?string $slug = 'store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Store';

    protected ?string $subheading = 'Browse available products and add them to your cart.';

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(
                Product::query()
                    ->available()
                    ->with('productable')
            )
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Price')
                    ->formatStateUsing(fn (int $state): string => format_money($state))
                    ->sortable(),
                TextColumn::make('available_spots')
                    ->label('Available Spots')
                    ->state(function (Product $record): string {
                        if (! ($record->productable instanceof HasCapacity)) {
                            return 'N/A';
                        }

                        $capacity = $record->productable->getAvailableCapacity();

                        return $capacity > 0 ? (string) $capacity : 'Sold Out';
                    })
                    ->badge()
                    ->color(function (Product $record): string {
                        if ($record->productable instanceof HasCapacity) {
                            return $record->productable->getAvailableCapacity() <= 0 ? 'danger' : 'success';
                        }

                        return 'success';
                    }),
            ])
            ->recordActions([
                Action::make('addToCart')
                    ->label('Add to Cart')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->color('primary')
                    ->disabled(function (Product $record): bool {
                        return $record->productable instanceof HasCapacity
                            && $record->productable->getAvailableCapacity() <= 0;
                    })
                    ->action(function (Product $record): void {
                        try {
                            $addToCart = new AddToCart;
                            $addToCart->handle(auth()->user(), $record);

                            $this->dispatch('refresh-sidebar');

                            Notification::make()
                                ->title('Added to cart')
                                ->body("\"{$record->name}\" has been added to your cart.")
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Could not add to cart')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
