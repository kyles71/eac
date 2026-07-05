<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\AddToCart;
use App\Contracts\HasCapacity;
use App\Enums\StoreView;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Product;
use App\Models\User;
use App\Support\Filament\CustomGiftCardAmountField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

final class Store extends TablePage
{
    public StoreView $storeView = StoreView::List;

    protected static ?string $title = 'Store';

    protected static ?string $slug = 'store';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 1;

    protected ?string $heading = 'Store';

    protected ?string $subheading = 'Browse available products and add them to your cart.';

    public function mount(): void
    {
        $this->storeView = $this->getUser()->getStoreView();
    }

    public function setStoreView(StoreView $storeView): void
    {
        if ($this->storeView === $storeView) {
            return;
        }

        $this->getUser()->update(['store_view' => $storeView]);
        $this->storeView = $storeView;
        $this->resetTable();
    }

    protected function makeTable(): Table
    {
        $query = Product::query()
            ->visibleTo($this->getUser())
            ->with('productable');

        if ($this->storeView === StoreView::Cards) {
            $query
                ->with('media')
                ->with([
                    'productable' => function (Relation $relation): void {
                        if (! $relation instanceof MorphTo) {
                            return;
                        }

                        $relation->morphWith([
                            Course::class => ['media'],
                            Costume::class => ['media'],
                        ]);
                    },
                ]);
        }

        return $this->makeBaseTable()
            ->query($query)
            ->recordUrl(fn (Product $record): string => ProductDetails::getUrl(['product' => $record]))
            ->columns($this->storeView === StoreView::Cards
                ? $this->getCardColumns()
                : $this->getListColumns())
            ->reorderableColumns(false)
            ->contentGrid($this->storeView === StoreView::Cards
                ? [
                    'default' => 1,
                    'md' => 2,
                    'xl' => 3,
                ]
                : null)
            ->paginated([15, 30, 45, 60, 'all'])
            ->headerActions([
                Action::make('listView')
                    ->label('List view')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->iconButton()
                    ->tooltip('List view')
                    ->color(fn (): string => $this->storeView === StoreView::List ? 'primary' : 'gray')
                    ->disabled(fn (): bool => $this->storeView === StoreView::List)
                    ->action(function (): void {
                        $this->setStoreView(StoreView::List);
                    }),
                Action::make('cardView')
                    ->label('Card view')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->iconButton()
                    ->tooltip('Card view')
                    ->color(fn (): string => $this->storeView === StoreView::Cards ? 'primary' : 'gray')
                    ->disabled(fn (): bool => $this->storeView === StoreView::Cards)
                    ->action(function (): void {
                        $this->setStoreView(StoreView::Cards);
                    }),
            ])
            ->recordActions([
                $this->getAddToCartAction(),
            ]);
    }

    /**
     * @return array<TextColumn>
     */
    private function getListColumns(): array
    {
        return [
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
            TextColumn::make('description')
                ->limit(50)
                ->tooltip(function (TextColumn $column): ?string {
                    $state = $column->getState();

                    if (! is_string($state) || str($state)->length() <= $column->getCharacterLimit()) {
                        return null;
                    }

                    return $state;
                })
                ->toggleable(),
            TextColumn::make('price')
                ->label('Price')
                ->state(fn (Product $record): string => $record->storefrontPriceLabel())
                ->sortable(),
            $this->getAvailableSpotsColumn(),
        ];
    }

    /**
     * @return array<Stack>
     */
    private function getCardColumns(): array
    {
        return [
            Stack::make([
                ImageColumn::make('card_image')
                    ->label('Product image')
                    ->state(fn (Product $record): ?string => $record->galleryImages()->first()?->getUrl())
                    ->defaultImageUrl(asset('images/product-placeholder.svg'))
                    ->imageHeight('12rem')
                    ->imageWidth('100%')
                    ->checkFileExistence(false)
                    ->extraImgAttributes(fn (Product $record): array => [
                        'alt' => $record->galleryImages()->isEmpty()
                            ? "No image available for {$record->name}"
                            : $record->name,
                        'class' => 'rounded-lg',
                    ]),
                Stack::make([
                    TextColumn::make('name')
                        ->weight(FontWeight::SemiBold)
                        ->size(TextSize::Large)
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('description')
                        ->limit(120)
                        ->wrap()
                        ->placeholder('No description available.'),
                    Split::make([
                        TextColumn::make('price')
                            ->state(fn (Product $record): string => $record->storefrontPriceLabel())
                            ->weight(FontWeight::Medium)
                            ->sortable(),
                        $this->getAvailableSpotsColumn()
                            ->grow(false),
                    ]),
                ])
                    ->space(2)
                    ->extraAttributes(['class' => 'px-4']),
            ])->space(2),
        ];
    }

    private function getAvailableSpotsColumn(): TextColumn
    {
        return TextColumn::make('available_spots')
            ->label('Available Spots')
            ->searchable(false)
            ->sortable(false)
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
            });
    }

    private function getAddToCartAction(): Action
    {
        return Action::make('addToCart')
            ->label('Add to Cart')
            ->icon(Heroicon::OutlinedShoppingCart)
            ->color('primary')
            ->modalHidden(fn (Product $record): bool => ! $record->requiresAddToCartInformation())
            ->modalHeading(fn (Product $record): string => $record->allowsCustomGiftCardAmount()
                ? 'Choose Gift Card Amount'
                : 'Add to Cart')
            ->modalSubmitActionLabel('Add to Cart')
            ->fillForm(fn (Product $record): array => [
                'custom_gift_card_amount' => $record->suggestedCustomGiftCardAmount(),
            ])
            ->schema(fn (Product $record): array => CustomGiftCardAmountField::schema($record))
            ->disabled(function (Product $record): bool {
                return $record->productable instanceof HasCapacity
                    && $record->productable->getAvailableCapacity() <= 0;
            })
            ->action(function (Product $record, array $data): void {
                try {
                    $addToCart = new AddToCart;
                    $addToCart->handle(
                        $this->getUser(),
                        $record,
                        customGiftCardAmount: CustomGiftCardAmountField::amountFromActionData($record, $data),
                    );

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
            });
    }

    private function getUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
