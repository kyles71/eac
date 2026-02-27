<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\CreateCheckoutSession;
use App\Actions\Store\RemoveFromCart;
use App\Actions\Store\UpdateCartQuantity;
use App\Models\CartItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use Livewire\Attributes\Url;

final class Cart extends Page implements HasTable
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

    protected static ?string $title = 'Cart';

    protected static ?string $slug = 'cart';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = CartItem::query()
            ->where('user_id', auth()->id())
            ->sum('quantity');

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'primary';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkout')
                ->label('Proceed to Checkout')
                ->icon(Heroicon::OutlinedCreditCard)
                ->color('success')
                ->disabled(fn (): bool => CartItem::query()->where('user_id', auth()->id())->doesntExist())
                ->requiresConfirmation()
                ->modalHeading('Proceed to Checkout')
                ->modalDescription('You will be redirected to Stripe to complete your payment.')
                ->action(function (): void {
                    try {
                        $createCheckout = app(CreateCheckoutSession::class);

                        $successUrl = CheckoutSuccess::getUrl();
                        $cancelUrl = self::getUrl();

                        $checkoutUrl = $createCheckout->handle(
                            auth()->user(),
                            $successUrl,
                            $cancelUrl,
                        );

                        $this->redirect($checkoutUrl);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Checkout failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(
                CartItem::query()
                    ->where('user_id', auth()->id())
                    ->with('product')
            )
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product'),
                TextColumn::make('product.price')
                    ->label('Unit Price')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                TextColumn::make('quantity')
                    ->label('Qty'),
                TextColumn::make('line_total')
                    ->label('Total')
                    ->state(function (CartItem $record): int {
                        /** @var \App\Models\Product $product */
                        $product = $record->product;

                        return $product->price * $record->quantity;
                    })
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->summarize(
                        Sum::make()
                            ->query(fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query->selectRaw('SUM(cart_items.quantity * products.price) as aggregate')
                                ->join('products', 'products.id', '=', 'cart_items.product_id'))
                            ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                            ->label('Total'),
                    ),
            ])
            ->recordActions([
                Action::make('updateQuantity')
                    ->label('Update Qty')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->form([
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(fn (CartItem $record): int => $record->quantity),
                    ])
                    ->action(function (CartItem $record, array $data): void {
                        try {
                            $updateQuantity = new UpdateCartQuantity;
                            $updateQuantity->handle(auth()->user(), $record->id, (int) $data['quantity']);

                            Notification::make()
                                ->title('Quantity updated')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Could not update quantity')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (CartItem $record): void {
                        try {
                            $removeFromCart = new RemoveFromCart;
                            $removeFromCart->handle(auth()->user(), $record->id);

                            Notification::make()
                                ->title('Item removed from cart')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Could not remove item')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Your cart is empty')
            ->emptyStateDescription('Browse the store to add products to your cart.')
            ->emptyStateActions([
                Action::make('browseStore')
                    ->label('Browse Store')
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->url(Store::getUrl()),
            ]);
    }
}
