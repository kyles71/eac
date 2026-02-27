<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\AddToCart;
use App\Models\Course;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use Livewire\Attributes\Url;

final class Store extends Page implements HasTable
{
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    #[Url(as: 'reordering')]
    public bool $isTableReordering = false;

    /**
     * @var array<string, mixed> | null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'grouping')]
    public ?string $tableGrouping = null;

    /**
     * @var ?string
     */
    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    protected static ?string $title = 'Store';

    protected static ?string $slug = 'store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Course Store';

    protected ?string $subheading = 'Browse available courses and add them to your cart.';

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

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
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('available_spots')
                    ->label('Available Spots')
                    ->state(function (Product $record): string {
                        if ($record->productable instanceof Course) {
                            $available = $record->productable->availableCapacity();

                            return $available > 0 ? (string) $available : 'Sold Out';
                        }

                        return 'N/A';
                    })
                    ->badge()
                    ->color(fn (Product $record): string => $record->productable instanceof Course && $record->productable->availableCapacity() <= 0 ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('addToCart')
                    ->label('Add to Cart')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->color('primary')
                    ->disabled(fn (Product $record): bool => $record->productable instanceof Course && $record->productable->availableCapacity() <= 0)
                    ->action(function (Product $record): void {
                        try {
                            $addToCart = new AddToCart;
                            $addToCart->handle(auth()->user(), $record);

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
