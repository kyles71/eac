<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\ApplyCode;
use App\Actions\Store\CreateOrder;
use App\Actions\Store\RemoveFromCart;
use App\Actions\Store\UpdateCartQuantity;
use App\Enums\OrderStatus;
use App\Filament\Shared\Schemas\OrderSummarySchema;
use App\Models\CartItem;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\RestrictedCredit;
use App\Support\LegalDocuments\PaymentPlanTerms;
use App\Support\PaymentPlanFee;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Livewire\Component;

final class Cart extends Page implements HasTable
{
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    public const string PAYMENT_OPTION_PAY_IN_FULL = 'pay_in_full';

    private const string PAYMENT_OPTION_TEMPLATE_PREFIX = 'template:';

    public ?int $appliedDiscountCodeId = null;

    public string $appliedDiscountDisplay = '';

    public bool $useCredit = false;

    public string $code = '';

    public string $selectedPaymentOption = self::PAYMENT_OPTION_PAY_IN_FULL;

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
                            TextInput::make('code')
                                ->label('Promo Code or Gift Card')
                                ->placeholder('Enter code')
                                ->afterContent(
                                    Action::make('applyCode')
                                        ->label('Apply')
                                        ->button()
                                        ->color('warning')
                                        ->size('sm')
                                        ->action(function (Component $livewire): void {
                                            $livewire->applyCode();
                                        })
                                        ->keyBindings(['enter']),
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
                            Checkbox::make('useCredit')
                                ->label(function (): string {
                                    $creditBalance = $this->getPreviewStoreCreditBalance();

                                    return 'Apply store credit ('.format_money($creditBalance).')';
                                })
                                ->live()
                                ->visible(fn (): bool => $this->getPreviewStoreCreditBalance() > 0),
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
            ->with('product.productable')
            ->get();
    }

    /**
     * Get pending orders whose reserved credits would be released before creating a new checkout order.
     *
     * @return Collection<int, Order>
     */
    public function getPendingOrdersProperty(): Collection
    {
        return Order::query()
            ->where('user_id', auth()->id())
            ->where('status', OrderStatus::Pending)
            ->get();
    }

    public function getPendingStoreCreditAmountProperty(): int
    {
        return $this->pendingOrders->sum('credit_applied');
    }

    public function getPendingRestrictedCreditAmountProperty(): int
    {
        return $this->pendingOrders->sum('restricted_credit_applied');
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
     * Get the restricted credit amount applicable to current cart items in cents.
     */
    public function getRestrictedCreditAmountProperty(): int
    {
        $totalRestricted = 0;
        $remaining = $this->subtotal - $this->discountAmount;

        /** @var CartItem $cartItem */
        foreach ($this->cartItems as $cartItem) {
            if ($remaining <= 0) {
                break;
            }

            $itemTotal = $cartItem->product->price * $cartItem->quantity;
            $available = $this->getPreviewRestrictedCreditForProduct($cartItem->product);

            if ($available > 0) {
                $applicable = min($available, $itemTotal, $remaining);
                $totalRestricted += $applicable;
                $remaining -= $applicable;
            }
        }

        return $totalRestricted;
    }

    /**
     * Get the credit amount to apply in cents.
     */
    public function getCreditAmountProperty(): int
    {
        if (! $this->useCredit) {
            return 0;
        }

        $creditBalance = $this->getPreviewStoreCreditBalance();

        return min($creditBalance, max(0, $this->subtotal - $this->discountAmount - $this->restrictedCreditAmount));
    }

    /**
     * Get the grand total in cents, including the payment plan fee when applicable.
     */
    public function getGrandTotalProperty(): int
    {
        return $this->totalBeforePaymentPlanFee + $this->paymentPlanFeeAmount;
    }

    public function getTotalBeforePaymentPlanFeeProperty(): int
    {
        return max(0, $this->subtotal - $this->discountAmount - $this->restrictedCreditAmount - $this->creditAmount);
    }

    public function getPaymentPlanFeeAmountProperty(): int
    {
        if ($this->selectedTemplate === null) {
            return 0;
        }

        return PaymentPlanFee::calculate($this->totalBeforePaymentPlanFee);
    }

    /**
     * Get available payment plan templates.
     *
     * @return Collection<int, PaymentPlanTemplate>
     */
    public function getPaymentPlanTemplatesProperty(): Collection
    {
        if ($this->cartItems->isEmpty()) {
            return collect();
        }

        return PaymentPlanTemplate::query()
            ->active()
            ->get()
            ->filter(fn (PaymentPlanTemplate $template): bool => $this->cartItems->every(
                fn (CartItem $cartItem): bool => $this->paymentPlanTemplateMatchesCartItem($template, $cartItem),
            ))
            ->values();
    }

    /**
     * Get the selected payment plan template.
     */
    public function getSelectedTemplateProperty(): ?PaymentPlanTemplate
    {
        $templateId = $this->selectedPaymentPlanTemplateId();

        if ($templateId === null) {
            return null;
        }

        return $this->paymentPlanTemplates->firstWhere('id', $templateId);
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

            $this->refreshCartState();
            $this->dispatch('refresh-sidebar');
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

            $this->refreshCartState();
            $this->dispatch('refresh-sidebar');
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

            $this->refreshCartState();
            $this->dispatch('refresh-sidebar');

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

    public function applyCode(): void
    {
        try {
            $applyCode = new ApplyCode;

            $productIds = CartItem::query()
                ->where('user_id', auth()->id())
                ->pluck('product_id')
                ->all();

            $result = $applyCode->handle(
                $this->code,
                auth()->user(),
                $this->subtotal,
                $productIds,
            );

            $this->code = '';

            if ($result['type'] === 'discount') {
                $discountCode = $result['discountCode'];
                $this->appliedDiscountCodeId = $discountCode->id;
                $this->appliedDiscountDisplay = "{$discountCode->code} ({$discountCode->formattedValue()} off)";

                Notification::make()
                    ->title('Discount applied')
                    ->body("Code {$discountCode->code} applied: {$discountCode->formattedValue()} off")
                    ->success()
                    ->send();
            } else {
                $giftCard = $result['giftCard'];

                Notification::make()
                    ->title('Gift card redeemed!')
                    ->body("Added {$giftCard->formattedInitialAmount()} to your store credit.")
                    ->success()
                    ->send();
            }
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Invalid code')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshCartState();
    }

    public function removeDiscount(): void
    {
        $this->appliedDiscountCodeId = null;
        $this->appliedDiscountDisplay = '';
        $this->refreshCartState();

        Notification::make()
            ->title('Discount removed')
            ->success()
            ->send();
    }

    public function updatedUseCredit(): void
    {
        $this->refreshCartState();
    }

    public function updatedSelectedPaymentOption(): void
    {
        unset($this->grandTotal, $this->paymentPlanFeeAmount);
        unset($this->selectedTemplate, $this->amountDueToday);
    }

    public function checkoutAction(): Action
    {
        return Action::make('checkout')
            ->label(fn (): string => $this->selectedTemplate === null
                ? 'Proceed to Checkout'
                : 'Proceed to Payment Plan Terms & Conditions')
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('warning')
            ->size('lg')
            ->disabled(fn (): bool => $this->cartItems->isEmpty())
            ->slideOver(false)
            ->modalSubmitActionLabel('Agree & Continue to Payment')
            ->modalHidden(fn (): bool => $this->selectedTemplate === null)
            ->schema(function (): array {
                if ($this->selectedTemplate === null) {
                    return [];
                }

                $termsVersion = PaymentPlanTerms::currentVersion();
                $hasTerms = $termsVersion !== null;

                return [
                    Grid::make()
                        ->columns(1)
                        ->extraAttributes([
                            'x-data' => '{
                                scrolledToBottom: false,
                                hasTerms: '.($hasTerms ? 'true' : 'false').',
                                unlockTermsIfReadable(element) {
                                    if (! this.hasTerms || element.clientHeight === 0) {
                                        return;
                                    }

                                    if (element.scrollHeight <= element.clientHeight + 2 || element.scrollTop + element.clientHeight >= element.scrollHeight - 2) {
                                        this.scrolledToBottom = true;
                                    }
                                },
                            }',
                        ])
                        ->schema([
                            TextEntry::make('terms_and_conditions')
                                ->hiddenLabel()
                                ->state(new HtmlString('
                                    <div
                                        class="h-32 overflow-y-scroll"
                                        x-init="
                                            const observer = new ResizeObserver(() => unlockTermsIfReadable($el));
                                            observer.observe($el);
                                            $nextTick(() => unlockTermsIfReadable($el));
                                        "
                                        @scroll="unlockTermsIfReadable($event.target)"
                                    >
                                    '.($termsVersion?->content ?? '<p>Payment plan terms are not available.</p>').'
                                    </div>')),
                            Checkbox::make('terms')
                                ->label('I have read and agree to the terms and conditions')
                                ->accepted()
                                ->required()
                                ->helperText(new HtmlString('<p>By checking this box, you agree to the terms and conditions.</p>
                                    <p class="text-red" x-show="! scrolledToBottom"><strong>You must scroll to the bottom of the Terms & Conditions to select this checkbox.</strong></p>'))
                                ->extraInputAttributes(['x-bind:disabled' => '!scrolledToBottom']),
                        ]),
                ];
            })
            ->action(function (): void {
                try {
                    $createOrder = app(CreateOrder::class);

                    $discountCode = $this->appliedDiscountCodeId !== null
                        ? DiscountCode::query()->find($this->appliedDiscountCodeId)
                        : null;

                    /** @var \App\Models\User $user */
                    $user = auth()->user();
                    $creditToApply = $this->useCredit ? $this->getPreviewStoreCreditBalance() : 0;

                    $paymentPlanTemplate = $this->selectedTemplate;

                    $order = $createOrder->handle(
                        $user,
                        $discountCode,
                        $creditToApply,
                        $paymentPlanTemplate,
                    );

                    if ($order->status === OrderStatus::Completed) {
                        $this->redirect(CheckoutSuccess::getUrl().'?order_id='.$order->id);
                    } else {
                        $this->redirect(Checkout::getUrl());
                    }
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

        $components[] = Select::make('selectedPaymentOption')
            ->label('Payment Option')
            ->options(fn (): array => [
                self::PAYMENT_OPTION_PAY_IN_FULL => 'Pay In Full',
                ...$this->paymentPlanTemplates
                    ->mapWithKeys(fn (PaymentPlanTemplate $template): array => [
                        $this->paymentOptionForTemplate($template) => "{$template->number_of_installments} {$template->frequency->value} Payments",
                    ])
                    ->all(),
            ])
            ->default(self::PAYMENT_OPTION_PAY_IN_FULL)
            ->searchable(false)
            ->selectablePlaceholder(false)
            ->visible(fn (): bool => $this->paymentPlanTemplates->isNotEmpty())
            ->live();

        $components = array_merge($components, OrderSummarySchema::make(
            subtotal: fn (): int => $this->subtotal,
            discountAmount: fn (): int => $this->discountAmount,
            discountLabel: function (): ?string {
                if ($this->discountAmount <= 0 || $this->appliedDiscountDisplay === '') {
                    return null;
                }

                return "Discount ({$this->appliedDiscountDisplay})";
            },
            restrictedCreditAmount: fn (): int => $this->restrictedCreditAmount,
            creditAmount: fn (): int => $this->creditAmount,
            paymentPlanFeeAmount: fn (): int => $this->paymentPlanFeeAmount,
            total: fn (): int => $this->grandTotal,
            template: fn (): ?PaymentPlanTemplate => $this->selectedTemplate,
            amountDueToday: fn (): ?int => $this->selectedTemplate !== null ? $this->amountDueToday : null,
        ));

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

    private function selectedPaymentPlanTemplateId(): ?int
    {
        if (! str_starts_with($this->selectedPaymentOption, self::PAYMENT_OPTION_TEMPLATE_PREFIX)) {
            return null;
        }

        $templateId = (int) mb_substr($this->selectedPaymentOption, mb_strlen(self::PAYMENT_OPTION_TEMPLATE_PREFIX));

        return $templateId > 0 ? $templateId : null;
    }

    private function paymentOptionForTemplate(PaymentPlanTemplate $template): string
    {
        return self::PAYMENT_OPTION_TEMPLATE_PREFIX.$template->id;
    }

    private function paymentPlanTemplateMatchesCartItem(PaymentPlanTemplate $template, CartItem $cartItem): bool
    {
        return $template->matchesCartItem($cartItem);
    }

    private function refreshCartState(): void
    {
        foreach ([
            'cartItems',
            'subtotal',
            'discountAmount',
            'restrictedCreditAmount',
            'creditAmount',
            'totalBeforePaymentPlanFee',
            'paymentPlanFeeAmount',
            'grandTotal',
            'pendingOrders',
            'pendingStoreCreditAmount',
            'pendingRestrictedCreditAmount',
            'paymentPlanTemplates',
            'selectedTemplate',
            'amountDueToday',
        ] as $property) {
            unset($this->{$property});
        }

        $this->flushCachedTableRecords();

        if (! $this->selectedPaymentPlanTemplateIsEligible()) {
            $this->selectedPaymentOption = self::PAYMENT_OPTION_PAY_IN_FULL;
        }
    }

    private function selectedPaymentPlanTemplateIsEligible(): bool
    {
        $templateId = $this->selectedPaymentPlanTemplateId();

        if ($templateId === null) {
            return true;
        }

        return $this->paymentPlanTemplates->contains(
            fn (PaymentPlanTemplate $template): bool => $template->id === $templateId,
        );
    }

    private function getPreviewStoreCreditBalance(): int
    {
        $user = auth()->user();

        $creditBalance = $user !== null && array_key_exists('credit_balance', $user->getAttributes())
            ? (int) $user->getAttribute('credit_balance')
            : (int) ($user?->newQuery()->whereKey(auth()->id())->value('credit_balance') ?? 0);

        return $creditBalance + $this->pendingStoreCreditAmount;
    }

    private function getPreviewRestrictedCreditForProduct(Product $product): int
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $pendingRestrictedCreditAmount = $this->pendingRestrictedCreditAmount;

        if ($pendingRestrictedCreditAmount <= 0) {
            return $user->getRestrictedCreditForProduct($product);
        }

        $restrictedCredits = $user->restrictedCredits()
            ->where('balance', '>=', 0)
            ->with('giftCardType.products')
            ->orderByDesc('created_at')
            ->get();

        $available = 0;

        /** @var RestrictedCredit $restrictedCredit */
        foreach ($restrictedCredits as $restrictedCredit) {
            $effectiveBalance = $restrictedCredit->balance;

            if ($pendingRestrictedCreditAmount > 0) {
                $effectiveBalance += $pendingRestrictedCreditAmount;
                $pendingRestrictedCreditAmount = 0;
            }

            if ($restrictedCredit->giftCardType->appliesToProduct($product)) {
                $available += $effectiveBalance;
            }
        }

        return $available;
    }
}
