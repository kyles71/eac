<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\ApplyDiscountCode;
use App\Actions\Store\RedeemGiftCard;
use App\Actions\Store\RemoveFromCart;
use App\Actions\Store\UpdateCartQuantity;
use App\Enums\PaymentPlanMethod;
use App\Models\CartItem;
use App\Models\DiscountCode;
use App\Models\PaymentPlanTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    public ?int $appliedDiscountCodeId = null;

    public string $appliedDiscountDisplay = '';

    public bool $useCredit = false;

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
            Action::make('redeemGiftCard')
                ->label('Redeem Gift Card')
                ->icon(Heroicon::OutlinedGift)
                ->color('gray')
                ->form([
                    TextInput::make('gift_card_code')
                        ->label('Gift Card Code')
                        ->required()
                        ->placeholder('Enter your gift card code'),
                ])
                ->action(function (array $data): void {
                    try {
                        $redeemGiftCard = new RedeemGiftCard;
                        $giftCard = $redeemGiftCard->handle(
                            (string) $data['gift_card_code'],
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('Gift card redeemed!')
                            ->body("Added {$giftCard->formattedInitialAmount()} to your store credit.")
                            ->success()
                            ->send();
                    } catch (InvalidArgumentException $e) {
                        Notification::make()
                            ->title('Invalid gift card')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('applyDiscount')
                ->label($this->appliedDiscountCodeId !== null ? 'Change Discount Code' : 'Apply Discount Code')
                ->icon(Heroicon::OutlinedTag)
                ->color('gray')
                ->form([
                    TextInput::make('discount_code')
                        ->label('Discount Code')
                        ->required()
                        ->placeholder('Enter your code'),
                ])
                ->action(function (array $data): void {
                    try {
                        $applyDiscount = new ApplyDiscountCode;

                        $subtotal = (int) CartItem::query()
                            ->where('user_id', auth()->id())
                            ->join('products', 'products.id', '=', 'cart_items.product_id')
                            ->selectRaw('SUM(cart_items.quantity * products.price) as total')
                            ->value('total');

                        $productIds = CartItem::query()
                            ->where('user_id', auth()->id())
                            ->pluck('product_id')
                            ->all();

                        $discountCode = $applyDiscount->handle(
                            (string) $data['discount_code'],
                            auth()->user(),
                            $subtotal,
                            $productIds,
                        );

                        $this->appliedDiscountCodeId = $discountCode->id;
                        $this->appliedDiscountDisplay = "{$discountCode->code} ({$discountCode->formattedValue()} off)";

                        Notification::make()
                            ->title('Discount applied')
                            ->body("Code {$discountCode->code} applied: {$discountCode->formattedValue()} off")
                            ->success()
                            ->send();
                    } catch (InvalidArgumentException $e) {
                        $this->appliedDiscountCodeId = null;
                        $this->appliedDiscountDisplay = '';

                        Notification::make()
                            ->title('Invalid discount code')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('removeDiscount')
                ->label('Remove Discount')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn (): bool => $this->appliedDiscountCodeId !== null)
                ->action(function (): void {
                    $this->appliedDiscountCodeId = null;
                    $this->appliedDiscountDisplay = '';

                    Notification::make()
                        ->title('Discount removed')
                        ->success()
                        ->send();
                }),
            Action::make('checkout')
                ->label('Proceed to Checkout')
                ->icon(Heroicon::OutlinedCreditCard)
                ->color('success')
                ->disabled(fn (): bool => CartItem::query()->where('user_id', auth()->id())->doesntExist())
                ->requiresConfirmation()
                ->modalHeading('Proceed to Checkout')
                ->modalDescription(function (): string {
                    $parts = [];

                    if ($this->appliedDiscountCodeId !== null) {
                        $parts[] = "Discount: {$this->appliedDiscountDisplay}";
                    }

                    /** @var \App\Models\User $user */
                    $user = auth()->user();
                    $creditBalance = $user->credit_balance ?? 0;

                    if ($creditBalance > 0) {
                        $parts[] = 'Store credit available: $'.number_format($creditBalance / 100, 2);
                    }

                    $parts[] = 'You will proceed to our secure checkout to enter your payment details.';

                    return implode("\n", $parts);
                })
                ->form([
                    Toggle::make('use_credit')
                        ->label(function (): string {
                            /** @var \App\Models\User $user */
                            $user = auth()->user();
                            $creditBalance = $user->credit_balance ?? 0;

                            return 'Apply store credit ($'.number_format($creditBalance / 100, 2).')';
                        })
                        ->default($this->useCredit)
                        ->visible(function (): bool {
                            /** @var \App\Models\User $user */
                            $user = auth()->user();

                            return ($user->credit_balance ?? 0) > 0;
                        }),
                    Select::make('payment_plan_template_id')
                        ->label('Payment Plan')
                        ->placeholder('Pay in full')
                        ->options(function (): array {
                            $cartItems = CartItem::query()
                                ->where('user_id', auth()->id())
                                ->with('product')
                                ->get();

                            if ($cartItems->isEmpty()) {
                                return [];
                            }

                            // Find templates that match any product in the cart
                            $templates = PaymentPlanTemplate::query()
                                ->active()
                                ->get();

                            $options = [];
                            /** @var PaymentPlanTemplate $template */
                            foreach ($templates as $template) {
                                $options[$template->id] = "{$template->name} ({$template->number_of_installments} x {$template->frequency->getLabel()})";
                            }

                            return $options;
                        })
                        ->visible(fn (): bool => PaymentPlanTemplate::query()->active()->exists())
                        ->reactive(),
                    Select::make('payment_plan_method')
                        ->label('Payment Plan Method')
                        ->options(PaymentPlanMethod::class)
                        ->default(PaymentPlanMethod::AutoCharge->value)
                        ->visible(fn (callable $get): bool => $get('payment_plan_template_id') !== null)
                        ->required(fn (callable $get): bool => $get('payment_plan_template_id') !== null),
                ])
                ->action(function (array $data): void {
                    $params = [];

                    if ($this->appliedDiscountCodeId !== null) {
                        $params['discountCodeId'] = $this->appliedDiscountCodeId;
                    }

                    if (! empty($data['use_credit'])) {
                        $params['use_credit'] = 1;
                    }

                    if (! empty($data['payment_plan_template_id'])) {
                        $params['payment_plan_template_id'] = $data['payment_plan_template_id'];
                    }

                    if (! empty($data['payment_plan_method'])) {
                        $params['payment_plan_method'] = $data['payment_plan_method'];
                    }

                    $url = Checkout::getUrl();
                    if (! empty($params)) {
                        $url .= '?'.http_build_query($params);
                    }

                    $this->redirect($url);
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
