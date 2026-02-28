<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\ApplyDiscountCode;
use App\Actions\Store\CreateCheckoutSession;
use App\Actions\Store\RedeemGiftCard;
use App\Actions\Store\RemoveFromCart;
use App\Actions\Store\UpdateCartQuantity;
use App\Enums\PaymentPlanMethod;
use App\Models\CartItem;
use App\Models\DiscountCode;
use App\Models\PaymentPlanTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

final class Cart extends Page implements HasTable
{
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    public ?int $appliedDiscountCodeId = null;

    public string $appliedDiscountDisplay = '';

    public bool $useCredit = false;

    public string $promoCode = '';

    public string $giftCardCode = '';

    public ?int $selectedPaymentPlanTemplateId = null;

    public ?string $selectedPaymentPlanMethod = null;

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
                Flex::make([
                    Section::make('Promo Codes & Gift Cards')
                        ->schema([
                            TextInput::make('promoCode')
                                ->label('Promo Code')
                                ->placeholder('Enter promo code')
                                ->afterContent(
                                    Action::make('applyPromoCode')
                                        ->label('Apply')
                                        ->button()
                                        ->color('warning')
                                        ->size('sm')
                                        ->action(function (Component $livewire): void {
                                            $livewire->applyPromoCode();
                                        }),
                                ),
                            Flex::make([
                                Text::make(fn (): string => "✓ {$this->appliedDiscountDisplay}")
                                    ->color('success'),
                                Action::make('removeDiscount')
                                    ->label('Remove')
                                    ->icon(Heroicon::OutlinedXMark)
                                    ->color('danger')
                                    ->size('sm')
                                    ->link()
                                    ->action(function (Component $livewire): void {
                                        $livewire->removeDiscount();
                                    }),
                            ])
                                ->visible(fn (): bool => $this->appliedDiscountCodeId !== null),
                            TextInput::make('giftCardCode')
                                ->label('Gift Card')
                                ->placeholder('Enter gift card code')
                                ->afterContent(
                                    Action::make('redeemGiftCard')
                                        ->label('Redeem')
                                        ->button()
                                        ->color('warning')
                                        ->size('sm')
                                        ->action(function (Component $livewire): void {
                                            $livewire->redeemGiftCard();
                                        }),
                                ),
                            Checkbox::make('useCredit')
                                ->label(function (): string {
                                    /** @var \App\Models\User $user */
                                    $user = auth()->user();
                                    $creditBalance = $user->credit_balance ?? 0;

                                    return "Apply store credit ({$this->formatMoney($creditBalance)})";
                                })
                                ->live()
                                ->visible(function (): bool {
                                    /** @var \App\Models\User $user */
                                    $user = auth()->user();

                                    return ($user->credit_balance ?? 0) > 0;
                                }),
                        ])
                        ->grow(false)
                        ->columnSpanFull(),
                    Text::make('')
                        ->columnSpanFull(),
                    Section::make('Order Summary')
                        ->schema($this->getOrderSummarySchema())
                        ->grow(false)
                        ->columnSpanFull(),
                ])
                    ->from('lg')
                    ->visible(fn (): bool => $this->cartItems->isNotEmpty()),
            ]);
    }

    /**
     * Get cart items for the authenticated user.
     *
     * @return Collection<int, CartItem>
     */
    public function getCartItemsProperty(): Collection
    {
        return CartItem::query()
            ->where('user_id', auth()->id())
            ->with('product')
            ->get();
    }

    /**
     * Get the subtotal in cents (before discounts/credits).
     */
    public function getSubtotalProperty(): int
    {
        return $this->cartItems->sum(fn (CartItem $item): int => $item->product->price * $item->quantity);
    }

    /**
     * Get the discount amount in cents.
     */
    public function getDiscountAmountProperty(): int
    {
        if ($this->appliedDiscountCodeId === null) {
            return 0;
        }

        $discountCode = DiscountCode::query()->find($this->appliedDiscountCodeId);

        if ($discountCode === null) {
            return 0;
        }

        return $discountCode->calculateDiscount($this->subtotal);
    }

    /**
     * Get the credit amount to apply in cents.
     */
    public function getCreditAmountProperty(): int
    {
        if (! $this->useCredit) {
            return 0;
        }

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $creditBalance = $user->credit_balance ?? 0;

        return min($creditBalance, $this->subtotal - $this->discountAmount);
    }

    /**
     * Get the grand total in cents (after discounts/credits).
     */
    public function getGrandTotalProperty(): int
    {
        return max(0, $this->subtotal - $this->discountAmount - $this->creditAmount);
    }

    /**
     * Get available payment plan templates.
     *
     * @return Collection<int, PaymentPlanTemplate>
     */
    public function getPaymentPlanTemplatesProperty(): Collection
    {
        return PaymentPlanTemplate::query()->active()->get();
    }

    /**
     * Get the selected payment plan template.
     */
    public function getSelectedTemplateProperty(): ?PaymentPlanTemplate
    {
        if ($this->selectedPaymentPlanTemplateId === null) {
            return null;
        }

        return $this->paymentPlanTemplates->firstWhere('id', $this->selectedPaymentPlanTemplateId);
    }

    /**
     * Get the amount due today based on payment plan selection.
     */
    public function getAmountDueTodayProperty(): int
    {
        if ($this->selectedTemplate === null) {
            return $this->grandTotal;
        }

        $amounts = $this->selectedTemplate->installmentAmounts($this->grandTotal);

        return $amounts['first'];
    }

    /**
     * Format cents as dollars.
     */
    public function formatMoney(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    public function incrementQuantity(int $cartItemId): void
    {
        try {
            $cartItem = CartItem::query()
                ->where('id', $cartItemId)
                ->where('user_id', auth()->id())
                ->first();

            if ($cartItem === null) {
                return;
            }

            $updateQuantity = new UpdateCartQuantity;
            $updateQuantity->handle(auth()->user(), $cartItemId, $cartItem->quantity + 1);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Could not update quantity')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function decrementQuantity(int $cartItemId): void
    {
        try {
            $cartItem = CartItem::query()
                ->where('id', $cartItemId)
                ->where('user_id', auth()->id())
                ->first();

            if ($cartItem === null) {
                return;
            }

            if ($cartItem->quantity <= 1) {
                return;
            }

            $updateQuantity = new UpdateCartQuantity;
            $updateQuantity->handle(auth()->user(), $cartItemId, $cartItem->quantity - 1);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Could not update quantity')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function removeItem(int $cartItemId): void
    {
        try {
            $removeFromCart = new RemoveFromCart;
            $removeFromCart->handle(auth()->user(), $cartItemId);

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
    }

    public function applyPromoCode(): void
    {
        try {
            $applyDiscount = new ApplyDiscountCode;

            $productIds = CartItem::query()
                ->where('user_id', auth()->id())
                ->pluck('product_id')
                ->all();

            $discountCode = $applyDiscount->handle(
                $this->promoCode,
                auth()->user(),
                $this->subtotal,
                $productIds,
            );

            $this->appliedDiscountCodeId = $discountCode->id;
            $this->appliedDiscountDisplay = "{$discountCode->code} ({$discountCode->formattedValue()} off)";
            $this->promoCode = '';

            Notification::make()
                ->title('Discount applied')
                ->body("Code {$discountCode->code} applied: {$discountCode->formattedValue()} off")
                ->success()
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Invalid promo code')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function removeDiscount(): void
    {
        $this->appliedDiscountCodeId = null;
        $this->appliedDiscountDisplay = '';

        Notification::make()
            ->title('Discount removed')
            ->success()
            ->send();
    }

    public function redeemGiftCard(): void
    {
        try {
            $redeemGiftCard = new RedeemGiftCard;
            $giftCard = $redeemGiftCard->handle(
                $this->giftCardCode,
                auth()->user(),
            );

            $this->giftCardCode = '';

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
    }

    public function updatedSelectedPaymentPlanTemplateId(): void
    {
        if ($this->selectedPaymentPlanTemplateId === null) {
            $this->selectedPaymentPlanMethod = null;
        } elseif ($this->selectedPaymentPlanMethod === null) {
            $this->selectedPaymentPlanMethod = PaymentPlanMethod::AutoCharge->value;
        }
    }

    public function checkoutAction(): Action
    {
        return Action::make('checkout')
            ->label('Review Order')
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('warning')
            ->size('lg')
            ->disabled(fn (): bool => $this->cartItems->isEmpty())
            ->requiresConfirmation()
            ->modalHeading('Review Your Order')
            ->modalDescription(function (): string {
                $parts = [];

                if ($this->appliedDiscountCodeId !== null) {
                    $parts[] = "Discount: {$this->appliedDiscountDisplay}";
                }

                if ($this->creditAmount > 0) {
                    $parts[] = 'Store credit: -'.$this->formatMoney($this->creditAmount);
                }

                if ($this->selectedTemplate !== null) {
                    $amounts = $this->selectedTemplate->installmentAmounts($this->grandTotal);
                    $parts[] = "Payment plan: {$this->selectedTemplate->number_of_installments} payments of {$this->formatMoney($amounts['remaining'])}";
                    $parts[] = "Amount due today: {$this->formatMoney($amounts['first'])}";
                }

                $parts[] = "Total: {$this->formatMoney($this->grandTotal)}";
                $parts[] = '';
                $parts[] = 'You will be redirected to Stripe to complete your payment (unless fully covered by discount/credit).';

                return implode("\n", $parts);
            })
            ->form([
                Toggle::make('use_credit')
                    ->label(function (): string {
                        /** @var \App\Models\User $user */
                        $user = auth()->user();
                        $creditBalance = $user->credit_balance ?? 0;

                        return 'Apply store credit ('.$this->formatMoney($creditBalance).')';
                    })
                    ->default($this->useCredit)
                    ->visible(function (): bool {
                        /** @var \App\Models\User $user */
                        $user = auth()->user();

                        return ($user->credit_balance ?? 0) > 0;
                    }),
                Select::make('payment_plan_method')
                    ->label('Payment Plan Method')
                    ->options(PaymentPlanMethod::class)
                    ->default($this->selectedPaymentPlanMethod ?? PaymentPlanMethod::AutoCharge->value)
                    ->visible(fn (): bool => $this->selectedTemplate !== null)
                    ->required(fn (): bool => $this->selectedTemplate !== null),
            ])
            ->action(function (array $data): void {
                try {
                    $createCheckout = app(CreateCheckoutSession::class);

                    $successUrl = CheckoutSuccess::getUrl();
                    $cancelUrl = self::getUrl();

                    $discountCode = $this->appliedDiscountCodeId !== null
                        ? DiscountCode::query()->find($this->appliedDiscountCodeId)
                        : null;

                    /** @var \App\Models\User $user */
                    $user = auth()->user();
                    $creditToApply = ! empty($data['use_credit']) ? ($user->credit_balance ?? 0) : 0;

                    $paymentPlanTemplate = $this->selectedTemplate;

                    $paymentPlanMethod = ! empty($data['payment_plan_method'])
                        ? PaymentPlanMethod::from($data['payment_plan_method'])
                        : ($this->selectedPaymentPlanMethod !== null
                            ? PaymentPlanMethod::from($this->selectedPaymentPlanMethod)
                            : null);

                    $checkoutUrl = $createCheckout->handle(
                        $user,
                        $successUrl,
                        $cancelUrl,
                        $discountCode,
                        $creditToApply,
                        $paymentPlanTemplate,
                        $paymentPlanMethod,
                    );

                    $this->redirect($checkoutUrl);
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Checkout failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Get the order summary schema components.
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    protected function getOrderSummarySchema(): array
    {
        $components = [];

        if ($this->paymentPlanTemplates->isNotEmpty()) {
            $components[] = Select::make('selectedPaymentPlanTemplateId')
                ->label('Payment Option')
                ->options(
                    $this->paymentPlanTemplates
                        ->mapWithKeys(fn (PaymentPlanTemplate $template): array => [
                            $template->id => 'Payment Plan',
                        ])
                        ->prepend('Pay in Full', '')
                        ->all()
                )
                ->live();
        }

        $totalComponents[] = Flex::make([
            Text::make("Subtotal")
                ->color('neutral')
                ->columnSpanFull(),
            Text::make(fn (): string => $this->formatMoney($this->subtotal))
                ->color('neutral')
                ->grow(false),
        ]);

        if ($this->discountAmount > 0) {
            $totalComponents[] = Flex::make([
                Text::make("Discount ({$this->appliedDiscountDisplay})")
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make(fn (): string => "-{$this->formatMoney($this->discountAmount)}")
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($this->creditAmount > 0) {
            $totalComponents[] = Flex::make([
                Text::make("Store Credit")
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make(fn (): string => "-{$this->formatMoney($this->creditAmount)}")
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        $totalComponents[] = Flex::make([
            Text::make("Total")
                ->size('md')
                ->weight(FontWeight::Bold)
                ->columnSpanFull(),
            Text::make(fn (): string => $this->formatMoney($this->grandTotal))
                ->size('md')
                ->weight(FontWeight::Bold)
                ->grow(false),
        ])
            ->extraAttributes(['class' => 'border-t border-gray-300 pt-2']);

        if ($this->selectedTemplate !== null) {
            $amounts = $this->selectedTemplate->installmentAmounts($this->grandTotal);

            $totalComponents[] =
                Text::make(fn () => "{$this->selectedTemplate->number_of_installments} payments of {$this->formatMoney($amounts['remaining'])}")
                    ->color('neutral')
                    ->extraAttributes(['class' => 'border-t border-gray-300 pt-2 w-full']);

            $totalComponents[] = Flex::make([
                Text::make("Amount Due Today")
                    ->weight(FontWeight::Bold)
                    ->columnSpanFull(),
                Text::make(fn (): string => $this->formatMoney($this->amountDueToday))
                    ->weight(FontWeight::Bold)
                    ->grow(false),
            ]);
        }

        $components[] = Grid::make(1)
            ->schema($totalComponents)
            ->gap(false);
        $components[] = $this->checkoutAction;

        return $components;
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
                    ->label('Name')
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('product.price')
                    ->label('Price')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('quantity')
                    ->label('Quantity')
                    ->alignCenter()
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('line_total')
                    ->label('Total')
                    ->state(fn (CartItem $record): string => '$'.number_format(($record->product->price * $record->quantity) / 100, 2))
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
            ])
            ->recordActions([
                Action::make('increment')
                    ->label('Add')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('primary')
                    ->iconButton()
                    ->action(function (CartItem $record): void {
                        $this->incrementQuantity($record->id);
                    }),
                Action::make('decrement')
                    ->label('Remove one')
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('primary')
                    ->iconButton()
                    ->disabled(fn (CartItem $record): bool => $record->quantity <= 1)
                    ->action(function (CartItem $record): void {
                        $this->decrementQuantity($record->id);
                    }),
                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->action(function (CartItem $record): void {
                        $this->removeItem($record->id);
                    }),
            ])
            ->deferLoading(false)
            ->reorderableColumns(false)
            ->paginated(false)
            ->emptyStateHeading('Your cart is empty')
            ->emptyStateDescription('Browse the store to add products to your cart.')
            ->emptyStateIcon(Heroicon::OutlinedShoppingCart)
            ->emptyStateActions([
                Action::make('browseStore')
                    ->label('Browse Store')
                    ->icon(Heroicon::OutlinedShoppingBag)
                    ->url(Store::getUrl()),
            ]);
    }
}
