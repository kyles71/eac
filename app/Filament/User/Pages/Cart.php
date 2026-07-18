<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\ApplyCode;
use App\Actions\Store\CreateOrder;
use App\Actions\Store\RemoveFromCart;
use App\Actions\Store\UpdateCartQuantity;
use App\Enums\OrderStatus;
use App\Filament\Shared\Schemas\OrderSummarySchema;
use App\Filament\Shared\Schemas\ProductQuestionCheckoutSchema;
use App\Filament\Shared\Schemas\ProductQuestionSchema;
use App\Models\CartItem;
use App\Models\DiscountCode;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Services\CreditLedgerService;
use App\Services\ProductQuestionAnswerService;
use App\Support\LegalDocuments\PaymentPlanTerms;
use App\Support\PaymentPlans\PaymentPlanBreakdown;
use App\Support\PaymentPlans\PaymentPlanBreakdownCalculator;
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
use LogicException;

/**
 * @property-read Collection<int, CartItem> $cartItems
 * @property-read int $subtotal
 * @property-read int $discountAmount
 * @property-read int $restrictedCreditAmount
 * @property-read int $creditAmount
 * @property-read int $grandTotal
 * @property-read int $totalBeforePaymentPlanFee
 * @property-read int $paymentPlanFeeAmount
 * @property-read Collection<int, PaymentPlanTemplate> $paymentPlanTemplates
 * @property-read PaymentPlanTemplate|null $selectedTemplate
 * @property-read int $amountDueToday
 * @property-read PaymentPlanBreakdown|null $paymentPlanBreakdown
 */
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
                                        ->action(function (self $livewire): void {
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
                                    ->action(function (self $livewire): void {
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
            ->with(['product.productable', 'product.questions'])
            ->get();
    }

    /**
     * Get the subtotal in cents (before discounts/credits).
     */
    public function getSubtotalProperty(): int
    {
        return $this->cartItems->sum(fn (CartItem $item): int => $item->lineTotal());
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
        if ($this->paymentPlanBreakdown !== null) {
            return $this->paymentPlanBreakdown->restrictedCreditAmount;
        }

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $items = $this->cartItems->map(fn (CartItem $cartItem): array => [
            'product' => $this->productForCartItem($cartItem),
            'amount' => $cartItem->lineTotal(),
        ]);

        return app(CreditLedgerService::class)->previewRestrictedAmount(
            $user,
            $items,
            max(0, $this->subtotal - $this->discountAmount),
        );
    }

    /**
     * Get the credit amount to apply in cents.
     */
    public function getCreditAmountProperty(): int
    {
        if ($this->paymentPlanBreakdown !== null) {
            return $this->paymentPlanBreakdown->creditAmount;
        }

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
        if ($this->paymentPlanBreakdown === null) {
            return 0;
        }

        return $this->paymentPlanBreakdown->fee;
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
            ->filter(fn (PaymentPlanTemplate $template): bool => $this->paymentPlanBreakdownForTemplate($template)->hasPrincipal())
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
        if ($this->paymentPlanBreakdown === null) {
            return $this->grandTotal;
        }

        return $this->paymentPlanBreakdown->amountDueToday;
    }

    public function getPaymentPlanBreakdownProperty(): ?PaymentPlanBreakdown
    {
        if ($this->selectedTemplate === null) {
            return null;
        }

        return $this->paymentPlanBreakdownForTemplate($this->selectedTemplate);
    }

    /** @param array<int|string, mixed> $questionAnswers */
    public function incrementQuantity(int $cartItemId, array $questionAnswers = []): void
    {
        try {
            $cartItem = CartItem::query()
                ->where('id', $cartItemId)
                ->where('user_id', auth()->id())
                ->first();

            if ($cartItem === null) {
                return;
            }

            app(UpdateCartQuantity::class)->handle(
                auth()->user(),
                $cartItemId,
                $cartItem->quantity + 1,
                $questionAnswers,
            );

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

            app(UpdateCartQuantity::class)->handle(auth()->user(), $cartItemId, $cartItem->quantity - 1);

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

    /** @param array<int|string, mixed> $questionAnswers */
    public function saveQuestionAnswers(int $cartItemId, array $questionAnswers): void
    {
        $cartItem = CartItem::query()
            ->where('id', $cartItemId)
            ->where('user_id', auth()->id())
            ->with(['product.productable', 'product.questions'])
            ->first();

        if (! $cartItem instanceof CartItem || ! $cartItem->product->asksPurchaserQuestionsWhenAddingToCart()) {
            return;
        }

        try {
            $normalizedAnswers = app(ProductQuestionAnswerService::class)->normalizeUnits(
                $cartItem->product,
                $questionAnswers,
                $cartItem->quantity,
                totalQuantity: $cartItem->quantity,
            );

            $cartItem->update([
                'question_answers' => $normalizedAnswers,
                'reminder_sent_at' => null,
            ]);

            $this->refreshCartState();

            Notification::make()
                ->title('Purchaser answers updated')
                ->success()
                ->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Could not update purchaser answers')
                ->body($exception->getMessage())
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
            ->modalHeading(fn (): string => $this->selectedTemplate === null
                ? 'Purchase Details'
                : 'Purchase Details & Payment Plan Terms')
            ->modalSubmitActionLabel(fn (): string => $this->selectedTemplate === null
                ? 'Continue to Payment'
                : 'Agree & Continue to Payment')
            ->modalHidden(fn (): bool => $this->selectedTemplate === null && ! $this->hasProductQuestions())
            ->before(function (Action $action): void {
                $incompleteCartItem = $this->firstCartItemWithIncompleteQuestionAnswers();

                if (! $incompleteCartItem instanceof CartItem) {
                    return;
                }

                Notification::make()
                    ->title('Purchaser answers needed')
                    ->body("Answer the purchaser questions for \"{$incompleteCartItem->product->name}\" before checking out.")
                    ->warning()
                    ->send();

                $action->halt();
            })
            ->schema(function (): array {
                $questionSchema = ProductQuestionCheckoutSchema::make($this->cartItems);

                if ($this->selectedTemplate === null) {
                    return $questionSchema;
                }

                $termsVersion = PaymentPlanTerms::currentVersion();
                $hasTerms = $termsVersion !== null;
                $termsContent = $termsVersion === null
                    ? '<p>Payment plan terms are not available.</p>'
                    : $termsVersion->content;

                return [
                    ...$questionSchema,
                    Section::make('Payment Plan Terms & Conditions')
                        ->description('Review and accept the payment plan terms before continuing.')
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
                                    '.$termsContent.'
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
            ->action(function (array $data): void {
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
                        $data['question_answers'] ?? [],
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
            paymentPlanItemsAmount: fn (): ?int => $this->paymentPlanBreakdown?->paymentPlanItemsAmount,
            paymentPlanDiscountAmount: fn (): int => $this->paymentPlanDiscountAmount(),
            paymentPlanRestrictedCreditAmount: fn (): int => $this->paymentPlanRestrictedCreditAmount(),
            paymentPlanCreditAmount: fn (): int => $this->paymentPlanCreditAmount(),
            payTodayItemsAmount: fn (): ?int => $this->paymentPlanBreakdown?->payInFullItemsAmount,
            payTodayDiscountAmount: fn (): int => $this->payInFullDiscountAmount(),
            payTodayRestrictedCreditAmount: fn (): int => $this->payInFullRestrictedCreditAmount(),
            payTodayCreditAmount: fn (): int => $this->payInFullCreditAmount(),
            paymentPlanFeeAmount: fn (): int => $this->paymentPlanFeeAmount,
            total: fn (): int => $this->grandTotal,
            template: fn (): ?PaymentPlanTemplate => $this->selectedTemplate,
            paymentPlanTotal: fn (): ?int => $this->paymentPlanBreakdown?->installmentTotal,
            amountDueToday: fn (): ?int => $this->selectedTemplate !== null ? $this->amountDueToday : null,
        ));

        $components[] = $this->checkoutAction();

        return $components;
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(
                CartItem::query()
                    ->where('user_id', auth()->id())
                    ->with(['product.productable', 'product.questions'])
            )
            ->columns([
                TextColumn::make('product.name')
                    ->label('Name')
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('unit_price')
                    ->label('Price')
                    ->state(fn (CartItem $record): string => $record->formattedEffectiveUnitPrice())
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
                    ->state(fn (CartItem $record): string => $record->formattedLineTotal())
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
                    ->modalHidden(fn (CartItem $record): bool => ! $record->product->asksPurchaserQuestionsWhenAddingToCart())
                    ->modalHeading(fn (CartItem $record): string => "Add Another {$record->product->name}")
                    ->modalSubmitActionLabel('Add to Cart')
                    ->fillForm(fn (): array => [
                        'question_answers' => [1 => []],
                    ])
                    ->schema(fn (CartItem $record): array => ProductQuestionSchema::make($record->product, 1))
                    ->action(function (CartItem $record, array $data): void {
                        $this->incrementQuantity(
                            $record->id,
                            is_array($data['question_answers'] ?? null)
                                ? $data['question_answers']
                                : [],
                        );
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
                Action::make('editQuestionAnswers')
                    ->label(fn (CartItem $record): string => $this->cartItemQuestionAnswersAreComplete($record)
                        ? 'Edit Answers'
                        : 'Answer Questions')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color(fn (CartItem $record): string => $this->cartItemQuestionAnswersAreComplete($record)
                        ? 'gray'
                        : 'warning')
                    ->iconButton()
                    ->visible(fn (CartItem $record): bool => $record->product->asksPurchaserQuestionsWhenAddingToCart())
                    ->modalHeading(fn (CartItem $record): string => "Purchaser Questions — {$record->product->name}")
                    ->modalSubmitActionLabel('Save Answers')
                    ->fillForm(fn (CartItem $record): array => [
                        'question_answers' => $record->storedQuestionAnswers(),
                    ])
                    ->schema(fn (CartItem $record): array => ProductQuestionSchema::make(
                        $record->product,
                        $record->quantity,
                    ))
                    ->action(function (CartItem $record, array $data): void {
                        $this->saveQuestionAnswers(
                            $record->id,
                            is_array($data['question_answers'] ?? null)
                                ? $data['question_answers']
                                : [],
                        );
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

    private function hasProductQuestions(): bool
    {
        return $this->cartItems->contains(
            fn (CartItem $cartItem): bool => ! $cartItem->product->asksPurchaserQuestionsWhenAddingToCart()
                && $cartItem->product->questions->isNotEmpty(),
        );
    }

    private function firstCartItemWithIncompleteQuestionAnswers(): ?CartItem
    {
        return $this->cartItems->first(
            fn (CartItem $cartItem): bool => $cartItem->product->asksPurchaserQuestionsWhenAddingToCart()
                && ! $this->cartItemQuestionAnswersAreComplete($cartItem),
        );
    }

    private function cartItemQuestionAnswersAreComplete(CartItem $cartItem): bool
    {
        return app(ProductQuestionAnswerService::class)->isComplete(
            $cartItem,
            $cartItem->storedQuestionAnswers(),
        );
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
            'paymentPlanTemplates',
            'selectedTemplate',
            'paymentPlanBreakdown',
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
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return app(CreditLedgerService::class)->previewUnrestrictedBalance($user);
    }

    private function paymentPlanBreakdownForTemplate(PaymentPlanTemplate $template): PaymentPlanBreakdown
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $calculator = app(PaymentPlanBreakdownCalculator::class);
        $itemsForCreditApplication = $calculator->itemsForCreditApplication($this->cartItems, $template);
        $lineAmountsAfterDiscount = $calculator->lineAmountsAfterDiscount(
            $this->cartItems,
            $template,
            $this->discountAmount,
        );

        $restrictedCreditApplication = app(CreditLedgerService::class)->previewRestrictedApplication(
            $user,
            $itemsForCreditApplication->map(fn (CartItem $cartItem): array => [
                'key' => $cartItem->id,
                'product' => $this->productForCartItem($cartItem),
                'amount' => $lineAmountsAfterDiscount[$cartItem->id] ?? 0,
            ]),
            max(0, array_sum($lineAmountsAfterDiscount)),
        );

        $creditAmount = 0;

        if ($this->useCredit) {
            $creditAmount = min(
                $this->getPreviewStoreCreditBalance(),
                max(0, $this->subtotal - $this->discountAmount - $restrictedCreditApplication['total']),
            );
        }

        return $calculator->calculate(
            items: $this->cartItems,
            template: $template,
            discountAmount: $this->discountAmount,
            restrictedCreditByItemKey: $restrictedCreditApplication['by_key'],
            creditAmount: $creditAmount,
        );
    }

    private function paymentPlanDiscountAmount(): int
    {
        $breakdown = $this->paymentPlanBreakdown;

        return $breakdown instanceof PaymentPlanBreakdown ? $breakdown->paymentPlanDiscountAmount : 0;
    }

    private function paymentPlanRestrictedCreditAmount(): int
    {
        $breakdown = $this->paymentPlanBreakdown;

        return $breakdown instanceof PaymentPlanBreakdown ? $breakdown->paymentPlanRestrictedCreditAmount : 0;
    }

    private function paymentPlanCreditAmount(): int
    {
        $breakdown = $this->paymentPlanBreakdown;

        return $breakdown instanceof PaymentPlanBreakdown ? $breakdown->paymentPlanCreditAmount : 0;
    }

    private function payInFullDiscountAmount(): int
    {
        $breakdown = $this->paymentPlanBreakdown;

        return $breakdown instanceof PaymentPlanBreakdown ? $breakdown->payInFullDiscountAmount : 0;
    }

    private function payInFullRestrictedCreditAmount(): int
    {
        $breakdown = $this->paymentPlanBreakdown;

        return $breakdown instanceof PaymentPlanBreakdown ? $breakdown->payInFullRestrictedCreditAmount : 0;
    }

    private function payInFullCreditAmount(): int
    {
        $breakdown = $this->paymentPlanBreakdown;

        return $breakdown instanceof PaymentPlanBreakdown ? $breakdown->payInFullCreditAmount : 0;
    }

    private function productForCartItem(CartItem $cartItem): Product
    {
        $product = $cartItem->product;

        if (! $product instanceof Product) {
            throw new LogicException('Cart items must have an associated product.');
        }

        return $product;
    }
}
