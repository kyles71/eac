<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\SendOrderReceipt;
use App\Actions\Store\UpdatePaymentPlanPaymentMethod;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanStatus;
use App\Filament\Actions\RedeemGiftCardAction;
use App\Filament\Shared\Schemas\ProductQuestionAnswerSchema;
use App\Models\GiftCard;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PaymentPlan;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use App\Services\DashboardAccountSummaryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;
use Throwable;

final class Billing extends Page
{
    public ?string $setupIntentClientSecret = null;

    public ?string $defaultPaymentMethodId = null;

    public ?int $paymentMethodTargetPlanId = null;

    /**
     * @var list<array{id: string, brand: string, last4: string, expires: string, is_default: bool}>
     */
    public array $paymentMethods = [];

    protected static ?string $title = 'Billing';

    protected static ?string $slug = 'billing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 5;

    public function mount(): void
    {
        if ($this->completeRedirectedPaymentMethodSetup()) {
            return;
        }

        $this->refreshPaymentMethods();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Billing')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Overview')
                            ->id('overview')
                            ->schema($this->getOverviewSchema()),
                        Tab::make('Orders & Receipts')
                            ->id('orders')
                            ->schema($this->getOrdersSchema()),
                        Tab::make('Payment Plans')
                            ->id('payment-plans')
                            ->schema($this->getPaymentPlansSchema()),
                        Tab::make('Recurring Private Lessons')
                            ->id('private-lessons')
                            ->visible(fn (): bool => $this->hasRecurringPrivateLessons())
                            ->schema($this->getRecurringPrivateLessonsSchema()),
                        Tab::make('Credits & Gift Cards')
                            ->id('credits')
                            ->schema($this->getCreditsAndGiftCardsSchema()),
                        Tab::make('Payment Methods')
                            ->id('payment-methods')
                            ->schema($this->getPaymentMethodsSchema()),
                    ]),
            ]);
    }

    public function startAddingPaymentMethod(?int $paymentPlanId = null): void
    {
        try {
            /** @var User $user */
            $user = auth()->user();

            $paymentPlan = $paymentPlanId === null
                ? null
                : $this->activePaymentPlanForUser($paymentPlanId);

            $metadata = [
                'user_id' => (string) $user->id,
            ];

            if ($paymentPlan !== null) {
                $metadata['payment_plan_id'] = (string) $paymentPlan->id;
            }

            $stripeService = $this->stripeService();
            $setupIntent = $stripeService->createSetupIntent($user, $metadata);

            $user->refresh();

            $this->paymentMethodTargetPlanId = $paymentPlan?->id;
            $this->setupIntentClientSecret = $setupIntent->client_secret;
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not start payment method setup')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function paymentMethodSetupCompleted(
        string $setupIntentId,
        string $paymentMethodId,
        bool $makeDefault = false,
    ): void {
        try {
            $this->completePaymentMethodSetup($setupIntentId, $paymentMethodId, $makeDefault);
            $this->unmountAction();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not save payment method')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function makeDefaultPaymentMethod(string $paymentMethodId): void
    {
        if (! $this->paymentMethodBelongsToUser($paymentMethodId)) {
            Notification::make()
                ->title('Payment method not found')
                ->danger()
                ->send();

            return;
        }

        try {
            /** @var User $user */
            $user = auth()->user();
            $stripeId = $this->stripeIdForUser($user);

            if ($stripeId === null) {
                return;
            }

            $this->stripeService()->setDefaultPaymentMethod($stripeId, $paymentMethodId);

            $this->refreshBillingContent();

            Notification::make()
                ->title('Default payment method updated')
                ->body('This does not change payment methods already assigned to payment plans.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not update payment method')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function removePaymentMethod(string $paymentMethodId): void
    {
        if (! $this->paymentMethodBelongsToUser($paymentMethodId)) {
            Notification::make()
                ->title('Payment method not found')
                ->danger()
                ->send();

            return;
        }

        $activePlansUsingMethod = $this->activeAutoChargePlansUsing($paymentMethodId);

        if ($activePlansUsingMethod > 0) {
            $planLabel = $activePlansUsingMethod === 1 ? 'plan' : 'plans';

            Notification::make()
                ->title('Payment method is in use')
                ->body("This payment method is assigned to {$activePlansUsingMethod} active payment {$planLabel}. Change those plans before removing it.")
                ->danger()
                ->send();

            return;
        }

        try {
            $this->stripeService()->detachPaymentMethod($paymentMethodId);
            $this->refreshBillingContent();

            Notification::make()
                ->title('Payment method removed')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not remove payment method')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getOverviewSchema(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $accountSummary = app(DashboardAccountSummaryService::class)->for($user);
        $creditBalance = $accountSummary['store_credit'];
        $restrictedCreditBalance = $accountSummary['limited_use_credit'];
        $openEnrollments = $accountSummary['open_enrollments'];
        $nextInstallment = $accountSummary['next_installment'];
        $nextPaymentTotal = $accountSummary['next_payment_total'];

        $summaryColumnSpan = ['default' => 1, 'md' => $restrictedCreditBalance > 0 ? 3 : 4];

        return [
            Grid::make([
                'default' => 1,
                'md' => 12,
            ])
                ->schema([
                    Section::make('Store Credit')
                        ->schema([
                            TextEntry::make('store_credit_balance')
                                ->hiddenLabel()
                                ->state(format_money($creditBalance)),
                        ])
                        ->columnSpan($summaryColumnSpan),
                    Section::make('Next Payment')
                        ->schema([
                            TextEntry::make('next_payment')
                                ->hiddenLabel()
                                ->state($nextInstallment !== null
                                    ? format_money($nextPaymentTotal).' due '.$nextInstallment->due_date->format('M j, Y')
                                    : 'No upcoming payments'),
                        ])
                        ->columnSpan($summaryColumnSpan),
                    Section::make('Open Seats')
                        ->schema([
                            TextEntry::make('open_enrollments')
                                ->hiddenLabel()
                                ->state((string) $openEnrollments),
                        ])
                        ->columnSpan($summaryColumnSpan),
                    Section::make('Limited Use Credit')
                        ->headerActions([
                            $this->viewLimitedUseCreditDetailsAction(),
                        ])
                        ->schema([
                            TextEntry::make('restricted_credit_balance')
                                ->hiddenLabel()
                                ->state(format_money($restrictedCreditBalance)),
                        ])
                        ->visible($restrictedCreditBalance > 0)
                        ->columnSpan(['default' => 1, 'md' => 3]),
                ]),
            Section::make('Recent Orders')
                ->schema($this->getRecentOrdersSchema()),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getRecurringPrivateLessonsSchema(): array
    {
        return [
            Flex::make([
                Icon::make(Heroicon::OutlinedExclamationTriangle)
                    ->key('recurring_private_lesson_payment_policy_icon')
                    ->color('warning')
                    ->size(IconSize::Large)
                    ->grow(false),
                TextEntry::make('recurring_private_lesson_payment_policy')
                    ->hiddenLabel()
                    ->state('Recurring private lessons must be paid at least 24 hours before the lesson starts. Unpaid lessons will be cancelled.'),
            ])->verticallyAlignCenter()->alignCenter(),
            EmbeddedTable::make(BillingRecurringPrivateLessonsTable::class)
                ->columnSpanFull(),
        ];
    }

    private function hasRecurringPrivateLessons(): bool
    {
        return RecurringPrivateLessonCharge::query()
            ->whereHas(
                'recurringPrivateLesson',
                fn (Builder $query): Builder => $query->where('user_id', auth()->id()),
            )
            ->exists();
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getRecentOrdersSchema(): array
    {
        $orders = Order::query()
            ->where('user_id', auth()->id())
            ->where('status', '!=', OrderStatus::Cancelled)
            ->latest()
            ->limit(5)
            ->get();

        if ($orders->isEmpty()) {
            return [
                TextEntry::make('recent_orders_empty')
                    ->hiddenLabel()
                    ->state('No orders yet.'),
            ];
        }

        return $orders
            ->map(fn (Order $order): Flex => Flex::make([
                TextEntry::make("recent_order_{$order->id}")
                    ->hiddenLabel()
                    ->state("#{$order->id} · {$order->created_at->format('M j, Y')} · {$order->status->value}"),
                TextEntry::make("recent_order_total_{$order->id}")
                    ->hiddenLabel()
                    ->state($order->formattedTotal())
                    ->grow(false),
            ]))
            ->all();
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getOrdersSchema(): array
    {
        $orders = Order::query()
            ->where('user_id', auth()->id())
            ->where('status', '!=', OrderStatus::Cancelled)
            ->with([
                'orderItems.product',
                'orderItems.questionAnswers',
                'discountCode',
                'refunds' => fn ($query) => $query
                    ->where('status', OrderRefundStatus::Succeeded)
                    ->latest('completed_at'),
            ])
            ->latest()
            ->get();

        if ($orders->isEmpty()) {
            return [
                Section::make('Orders')
                    ->schema([
                        TextEntry::make('orders_empty')
                            ->hiddenLabel()
                            ->state('No orders yet.'),
                    ]),
            ];
        }

        return $orders
            ->map(fn (Order $order): Section => Section::make("Order #{$order->id}")
                ->schema([
                    Grid::make()
                        ->columns([
                            'default' => 1,
                            'md' => 4,
                        ])
                        ->schema([
                            TextEntry::make("order_{$order->id}_date")
                                ->label('Date')
                                ->state($order->created_at)
                                ->dateTime(),
                            TextEntry::make("order_{$order->id}_status")
                                ->label('Status')
                                ->state($order->status)
                                ->badge(),
                            TextEntry::make("order_{$order->id}_items")
                                ->label('Items')
                                ->state((string) $order->orderItems->sum('quantity')),
                            TextEntry::make("order_{$order->id}_total")
                                ->label('Total')
                                ->state($order->formattedTotal()),
                        ]),
                    Actions::make([
                        $this->receiptAction($order),
                        ...$order->refunds
                            ->map(fn (OrderRefund $refund): Action => $this->refundReceiptAction($refund))
                            ->all(),
                        $this->resendReceiptAction($order),
                    ]),
                ])
                ->compact())
            ->all();
    }

    private function receiptAction(Order $order): Action
    {
        return Action::make("receipt_{$order->id}")
            ->label('View Receipt')
            ->icon(Heroicon::OutlinedDocumentText)
            ->modalHeading("Receipt for Order #{$order->id}")
            ->schema($this->receiptSchema($order))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function resendReceiptAction(Order $order): Action
    {
        return Action::make("resend_receipt_{$order->id}")
            ->label('Resend Receipt')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible($order->status === OrderStatus::Completed)
            ->action(function () use ($order): void {
                try {
                    $queued = app(SendOrderReceipt::class)->handle($order, resend: true);

                    $notification = Notification::make()
                        ->title($queued ? 'Receipt email queued' : 'Receipt email is disabled');

                    ($queued ? $notification->success() : $notification->warning())->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not resend receipt')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function refundReceiptAction(OrderRefund $refund): Action
    {
        return Action::make("refund_receipt_{$refund->id}")
            ->label("View Refund Receipt #{$refund->id}")
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->modalHeading("Refund Receipt #{$refund->id}")
            ->schema($this->refundReceiptSchema($refund))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function refundReceiptSchema(OrderRefund $refund): array
    {
        $refund->loadMissing('order');
        $order = $refund->order;
        $amountCollected = $order->capturedStripeAmount();
        $totalRefunded = $order->successfulRefundAmount();

        return [
            Grid::make()
                ->columns(2)
                ->schema([
                    TextEntry::make("refund_{$refund->id}_order")
                        ->label('Order')
                        ->state("#{$order->id}"),
                    TextEntry::make("refund_{$refund->id}_date")
                        ->label('Refund Date')
                        ->state($refund->completed_at)
                        ->dateTime(),
                    TextEntry::make("refund_{$refund->id}_amount")
                        ->label('Refund Amount')
                        ->state($refund->formattedAmount()),
                    TextEntry::make("refund_{$refund->id}_status")
                        ->label('Status')
                        ->state($refund->status)
                        ->badge(),
                    TextEntry::make("refund_{$refund->id}_order_total")
                        ->label('Original Order Total')
                        ->state($order->formattedTotal()),
                    TextEntry::make("refund_{$refund->id}_amount_collected")
                        ->label('Amount Collected')
                        ->state(format_money($amountCollected)),
                    TextEntry::make("refund_{$refund->id}_total_refunded")
                        ->label('Total Refunded')
                        ->state(format_money($totalRefunded)),
                    TextEntry::make("refund_{$refund->id}_net_paid")
                        ->label('Net Paid')
                        ->state(format_money(max(0, $amountCollected - $totalRefunded))),
                ]),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function receiptSchema(Order $order): array
    {
        $order->loadMissing(['orderItems.product', 'orderItems.questionAnswers', 'discountCode']);

        $items = [];

        foreach ($order->orderItems as $item) {
            $items[] = TextEntry::make("receipt_item_{$item->id}")
                ->label($item->product->name)
                ->state("Qty {$item->quantity} × {$item->formattedUnitPrice()} = {$item->formattedTotalPrice()}");

            array_push($items, ...ProductQuestionAnswerSchema::forOrderItem($item, 'receipt'));
        }

        return [
            Grid::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('receipt_date')
                        ->label('Date')
                        ->state($order->created_at)
                        ->dateTime(),
                    TextEntry::make('receipt_status')
                        ->label('Status')
                        ->state($order->status)
                        ->badge(),
                ]),
            Section::make('Items')
                ->schema($items),
            Grid::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('receipt_subtotal')
                        ->label('Subtotal')
                        ->state($order->formattedSubtotal()),
                    TextEntry::make('receipt_discount')
                        ->label('Discount')
                        ->state(format_money($order->discount_amount)),
                    TextEntry::make('receipt_restricted_credit')
                        ->label('Limited Use Credit')
                        ->state(format_money($order->restricted_credit_applied)),
                    TextEntry::make('receipt_store_credit')
                        ->label('Store Credit')
                        ->state(format_money($order->credit_applied)),
                    TextEntry::make('receipt_payment_plan_fee')
                        ->label('Payment Plan Fee')
                        ->state(format_money($order->payment_plan_fee)),
                    TextEntry::make('receipt_total')
                        ->label('Total')
                        ->state($order->formattedTotal()),
                ]),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getPaymentPlansSchema(): array
    {
        $plans = PaymentPlan::query()
            ->whereHas('order', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->where('status', '!=', OrderStatus::Cancelled))
            ->with(['order.paymentPlanTermsVersion', 'template', 'installments'])
            ->latest()
            ->get();

        if ($plans->isEmpty()) {
            return [
                Section::make('Payment Plans')
                    ->schema([
                        TextEntry::make('payment_plans_empty')
                            ->hiddenLabel()
                            ->state('No payment plans yet.'),
                    ]),
            ];
        }

        $hasMultipleTermsVersions = $plans
            ->pluck('order.paymentPlanTermsVersion.id')
            ->filter()
            ->unique()
            ->count() > 1;

        return [
            ...$this->paymentPlanTermsLinkSchema($plans),
            ...$plans
                ->map(fn (PaymentPlan $plan): Section => Section::make("Order #{$plan->order_id}")
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 3,
                        ])
                            ->schema([
                                TextEntry::make("plan_{$plan->id}_total")
                                    ->label('Total')
                                    ->state(format_money($plan->total_amount)),
                                TextEntry::make("plan_{$plan->id}_paid")
                                    ->label('Paid')
                                    ->state(format_money($plan->amountPaid())),
                                TextEntry::make("plan_{$plan->id}_remaining")
                                    ->label('Remaining')
                                    ->state(format_money($plan->remainingBalance())),
                                TextEntry::make("plan_{$plan->id}_status")
                                    ->label('Status')
                                    ->state(fn (): PaymentPlanStatus => PaymentPlanStatus::forPaymentPlan($plan))
                                    ->badge(),
                                TextEntry::make("plan_{$plan->id}_payment_method")
                                    ->label('Payment Method')
                                    ->state(fn (): string => $this->paymentMethodLabel($plan->stripe_payment_method_id)),
                                TextEntry::make("plan_{$plan->id}_terms")
                                    ->label('Terms & Conditions')
                                    ->state($plan->order?->paymentPlanTermsVersion?->versionLabel())
                                    ->color('primary')
                                    ->icon(Heroicon::OutlinedDocumentText)
                                    ->url(fn (): ?string => $plan->order?->paymentPlanTermsVersion === null
                                        ? null
                                        : route('legal-documents.versions.show', $plan->order->paymentPlanTermsVersion))
                                    ->openUrlInNewTab()
                                    ->visible($hasMultipleTermsVersions),
                            ]),
                        Actions::make([
                            $this->changePaymentMethodAction($plan),
                            $this->installmentsAction($plan),
                        ]),
                    ])
                    ->compact())
                ->all(),
        ];
    }

    private function changePaymentMethodAction(PaymentPlan $plan): Action
    {
        return Action::make("change_plan_payment_method_{$plan->id}")
            ->label('Change Payment Method')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (): bool => ! $plan->isFullyPaid())
            ->modalDescription('This changes the card used for future installments on this payment plan only.')
            ->fillForm(fn (): array => [
                'stripe_payment_method_id' => $this->availablePaymentMethodId(
                    $plan->stripe_payment_method_id ?? $this->defaultPaymentMethodId,
                ),
            ])
            ->schema([
                Select::make('stripe_payment_method_id')
                    ->label('Saved Payment Method')
                    ->options(fn (): array => $this->paymentMethodOptions())
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->extraModalFooterActions([
                Action::make('addNewPaymentMethod')
                    ->label('Add New Payment Method')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modal()
                    ->modalHeading('Add New Payment Method')
                    ->mountUsing(function () use ($plan): void {
                        $this->startAddingPaymentMethod($plan->id);
                    })
                    ->modalContent(fn (): ViewContract => view(
                        'filament.user.pages.billing-add-plan-payment-method',
                        ['paymentPlanId' => $plan->id],
                    ))
                    ->modalSubmitAction(false)
                    ->cancelParentActions(),
            ])
            ->action(function (array $data) use ($plan): void {
                try {
                    $paymentMethodId = $data['stripe_payment_method_id'] ?? null;

                    if (! is_string($paymentMethodId) || ! $this->paymentMethodBelongsToUser($paymentMethodId)) {
                        throw new InvalidArgumentException('Choose a saved payment method.');
                    }

                    app(UpdatePaymentPlanPaymentMethod::class)->handle(
                        $this->activePaymentPlanForUser($plan->id),
                        $paymentMethodId,
                    );

                    $this->refreshBillingContent();

                    Notification::make()
                        ->title('Payment method updated')
                        ->body('Future installments for this payment plan will use the selected card.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not update payment method')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function installmentsAction(PaymentPlan $plan): Action
    {
        return Action::make("installments_{$plan->id}")
            ->label('View Installments')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading("Installments for Order #{$plan->order_id}")
            ->schema(
                $plan->installments
                    ->sortBy('installment_number')
                    ->map(fn (Installment $installment): Section => Section::make("#{$installment->installment_number}")
                        ->schema([
                            Grid::make(4)
                                ->schema([
                                    TextEntry::make("installment_{$installment->id}_amount")
                                        ->label('Amount')
                                        ->state(format_money($installment->amount)),
                                    TextEntry::make("installment_{$installment->id}_due")
                                        ->label('Due Date')
                                        ->state($installment->due_date)
                                        ->date('M j, Y'),
                                    TextEntry::make("installment_{$installment->id}_status")
                                        ->label('Status')
                                        ->state($installment->paymentStatusLabel())
                                        ->color($installment->paymentStatusColor())
                                        ->badge(),
                                    TextEntry::make("installment_{$installment->id}_paid")
                                        ->label('Paid At')
                                        ->state($installment->paid_at)
                                        ->dateTime('M j, Y')
                                        ->placeholder('—'),
                                ]),
                        ])
                        ->compact())
                    ->all()
            )
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getCreditsAndGiftCardsSchema(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            Actions::make([
                RedeemGiftCardAction::make()
                    ->afterSuccessfulRedemption(function (): void {
                        $this->refreshBillingContent(refreshPaymentMethods: false);
                    }),
            ]),
            Section::make('Unredeemed Gift Cards')
                ->schema($this->giftCardSchema(redeemed: false)),
            EmbeddedTable::make(BillingCreditGrantsTable::class, [
                'type' => BillingCreditGrantsTable::TYPE_STORE,
            ])
                ->columnSpanFull(),
            EmbeddedTable::make(BillingCreditGrantsTable::class, [
                'type' => BillingCreditGrantsTable::TYPE_LIMITED_USE,
            ])
                ->extraAttributes([
                    'id' => 'limited-use-credits',
                    'tabindex' => '-1',
                ])
                ->visible($user->availableRestrictedCreditBalance() > 0)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function giftCardSchema(bool $redeemed): array
    {
        $query = GiftCard::query()
            ->with('giftCardType')
            ->when(
                $redeemed,
                fn ($query) => $query->where('redeemed_by_user_id', auth()->id()),
                fn ($query) => $query
                    ->where('purchased_by_user_id', auth()->id())
                    ->whereNull('redeemed_at')
            )
            ->latest();

        $giftCards = $query->get();

        if ($giftCards->isEmpty()) {
            return [
                TextEntry::make($redeemed ? 'redeemed_gift_cards_empty' : 'unredeemed_gift_cards_empty')
                    ->hiddenLabel()
                    ->state($redeemed ? 'No redeemed gift cards yet.' : 'No unredeemed purchased gift cards.'),
            ];
        }

        return $giftCards
            ->map(fn (GiftCard $giftCard): TextEntry => TextEntry::make(($redeemed ? 'redeemed' : 'unredeemed')."_gift_card_{$giftCard->id}")
                ->label($giftCard->code)
                ->state($giftCard->formattedInitialAmount().' · '.($giftCard->giftCardType?->restrictionSummary() ?? 'Unrestricted'))
                ->copyable())
            ->all();
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getPaymentMethodsSchema(): array
    {
        $savedPaymentMethods = collect($this->paymentMethods)
            ->map(fn (array $paymentMethod): Flex => Flex::make([
                TextEntry::make("payment_method_{$paymentMethod['id']}")
                    ->hiddenLabel()
                    ->state($this->paymentMethodDescription($paymentMethod)
                        .($paymentMethod['is_default'] ? ' (Default)' : ''))
                    ->columnSpanFull(),
                Actions::make([
                    Action::make("default_payment_method_{$paymentMethod['id']}")
                        ->label('Make Default')
                        ->icon(Heroicon::OutlinedStar)
                        ->iconButton()
                        ->visible(! $paymentMethod['is_default'])
                        ->action(function () use ($paymentMethod): void {
                            $this->makeDefaultPaymentMethod($paymentMethod['id']);
                        }),
                    Action::make("remove_payment_method_{$paymentMethod['id']}")
                        ->label('Remove')
                        ->icon(Heroicon::OutlinedTrash)
                        ->iconButton()
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function () use ($paymentMethod): void {
                            $this->removePaymentMethod($paymentMethod['id']);
                        }),
                ])
                    ->grow(false),
            ])->from('md'))
            ->all();

        if ($savedPaymentMethods === []) {
            $savedPaymentMethods[] = TextEntry::make('payment_methods_empty')
                ->hiddenLabel()
                ->state('No saved payment methods.');
        }

        $savedPaymentMethods[] = Actions::make([
            Action::make('addPaymentMethod')
                ->label('Add Payment Method')
                ->icon(Heroicon::OutlinedPlus)
                ->action('startAddingPaymentMethod'),
        ]);

        return [
            TextEntry::make('payment_methods_help')
                ->hiddenLabel()
                ->state('Your account default is preferred during new checkouts. Each payment plan can use a different payment method.'),
            Grid::make([
                'default' => 1,
                'lg' => 2,
            ])
                ->schema([
                    Section::make('Saved Payment Methods')
                        ->schema($savedPaymentMethods)
                        ->columnSpan(1),
                    Section::make('Add Payment Method')
                        ->schema([
                            View::make('filament.user.pages.billing-payment-methods'),
                        ])
                        ->visible(fn (): bool => $this->setupIntentClientSecret !== null
                            && $this->paymentMethodTargetPlanId === null)
                        ->columnSpan(1),
                ]),
        ];
    }

    private function refreshPaymentMethods(bool $notifyOnError = false): void
    {
        /** @var User|null $user */
        $user = auth()->user();

        $stripeId = $user === null
            ? null
            : $this->stripeIdForUser($user);

        if ($user === null || $stripeId === null) {
            $this->paymentMethods = [];
            $this->defaultPaymentMethodId = null;

            return;
        }

        try {
            $stripeService = $this->stripeService();
            $customer = $stripeService->createOrGetCustomer($user);
            $defaultPaymentMethod = data_get($customer, 'invoice_settings.default_payment_method');
            $defaultPaymentMethodId = data_get($defaultPaymentMethod, 'id');
            $this->defaultPaymentMethodId = is_string($defaultPaymentMethod)
                ? $defaultPaymentMethod
                : (is_string($defaultPaymentMethodId) ? $defaultPaymentMethodId : null);

            $this->paymentMethods = collect($stripeService->listPaymentMethods($customer->id))
                ->map(fn ($paymentMethod): array => [
                    'id' => (string) data_get($paymentMethod, 'id'),
                    'brand' => ucfirst((string) data_get($paymentMethod, 'card.brand', 'Card')),
                    'last4' => (string) data_get($paymentMethod, 'card.last4', '0000'),
                    'expires' => $this->paymentMethodExpiration($paymentMethod),
                    'is_default' => data_get($paymentMethod, 'id') === $this->defaultPaymentMethodId,
                ])
                ->all();
        } catch (Throwable $exception) {
            $this->paymentMethods = [];
            $this->defaultPaymentMethodId = null;

            if ($notifyOnError) {
                Notification::make()
                    ->title('Could not load payment methods')
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();
            }
        }
    }

    private function refreshBillingContent(bool $refreshPaymentMethods = true): void
    {
        if ($refreshPaymentMethods) {
            $this->refreshPaymentMethods(notifyOnError: true);
        }

        $this->cacheSchema('content', null);
        $this->dispatch(BillingCreditGrantsTable::REFRESH_EVENT)->to(BillingCreditGrantsTable::class);
    }

    private function paymentMethodBelongsToUser(string $paymentMethodId): bool
    {
        $this->refreshPaymentMethods(notifyOnError: true);

        return collect($this->paymentMethods)
            ->contains(fn (array $paymentMethod): bool => $paymentMethod['id'] === $paymentMethodId);
    }

    private function activeAutoChargePlansUsing(string $paymentMethodId): int
    {
        return PaymentPlan::query()
            ->whereHas('order', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->where('status', '!=', OrderStatus::Cancelled))
            ->where(function ($query) use ($paymentMethodId): void {
                $query->where('stripe_payment_method_id', $paymentMethodId);

                if ($paymentMethodId === $this->defaultPaymentMethodId) {
                    $query->orWhereNull('stripe_payment_method_id');
                }
            })
            ->whereHas('installments', fn ($query) => $query
                ->where('status', '!=', InstallmentStatus::Paid->value))
            ->count();
    }

    /**
     * @param  EloquentCollection<int, PaymentPlan>  $plans
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function paymentPlanTermsLinkSchema(EloquentCollection $plans): array
    {
        $versions = $plans
            ->map(fn (PaymentPlan $plan) => $plan->order?->paymentPlanTermsVersion)
            ->filter()
            ->unique('id')
            ->values();

        if ($versions->isEmpty()) {
            return [];
        }

        if ($versions->count() === 1) {
            $version = $versions->first();

            return [
                Section::make('Terms & Conditions')
                    ->schema([
                        Actions::make([
                            Action::make('view_payment_plan_terms')
                                ->label('All payment plans below have been agreed to under these Terms & Conditions')
                                ->icon(Heroicon::OutlinedDocumentText)
                                ->url(route('legal-documents.versions.show', $version))
                                ->openUrlInNewTab(),
                        ]),
                    ])
                    ->compact(),
            ];
        }

        return [
            Section::make('Terms & Conditions')
                ->schema([
                    Actions::make(
                        $versions
                            ->map(fn ($version): Action => Action::make("view_payment_plan_terms_{$version->id}")
                                ->label("View Terms & Conditions {$version->versionLabel()}")
                                ->icon(Heroicon::OutlinedDocumentText)
                                ->url(route('legal-documents.versions.show', $version))
                                ->openUrlInNewTab())
                            ->all()
                    ),
                ])
                ->compact(),
        ];
    }

    private function viewLimitedUseCreditDetailsAction(): Action
    {
        return Action::make('viewLimitedUseCreditDetails')
            ->label('View Details')
            ->icon(Heroicon::OutlinedArrowRight)
            ->url(fn (): string => self::getUrl(['tab' => 'credits']).'#limited-use-credits');
    }

    private function completeRedirectedPaymentMethodSetup(): bool
    {
        $setupIntentId = request()->query('setup_intent');

        if (! is_string($setupIntentId) || $setupIntentId === '') {
            return false;
        }

        $paymentPlanId = null;

        try {
            $paymentPlanId = $this->completePaymentMethodSetup(
                setupIntentId: $setupIntentId,
                makeDefault: request()->boolean('make_default'),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not save payment method')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        $this->redirect(self::getUrl([
            'tab' => $paymentPlanId !== null ? 'payment-plans' : 'payment-methods',
        ]));

        return true;
    }

    private function completePaymentMethodSetup(
        string $setupIntentId,
        ?string $paymentMethodId = null,
        bool $makeDefault = false,
    ): ?int {
        /** @var User $user */
        $user = auth()->user();
        $stripeId = $this->stripeIdForUser($user);

        if ($stripeId === null) {
            throw new InvalidArgumentException('Stripe customer not found.');
        }

        $setupIntent = $this->stripeService()->retrieveSetupIntent($setupIntentId);
        $setupIntentCustomerId = data_get($setupIntent, 'customer.id', data_get($setupIntent, 'customer'));
        $setupIntentPaymentMethodId = data_get($setupIntent, 'payment_method.id', data_get($setupIntent, 'payment_method'));
        $setupIntentUserId = data_get($setupIntent, 'metadata.user_id');
        $setupIntentPaymentPlanId = data_get($setupIntent, 'metadata.payment_plan_id');

        if ($setupIntent->status !== 'succeeded'
            || $setupIntentCustomerId !== $stripeId
            || $setupIntentUserId !== (string) $user->id
            || ! is_string($setupIntentPaymentMethodId)
            || ($paymentMethodId !== null && $setupIntentPaymentMethodId !== $paymentMethodId)) {
            throw new InvalidArgumentException('Payment method setup could not be verified.');
        }

        if (! $this->paymentMethodBelongsToUser($setupIntentPaymentMethodId)) {
            throw new InvalidArgumentException('Payment method not found.');
        }

        $paymentPlan = is_string($setupIntentPaymentPlanId) && ctype_digit($setupIntentPaymentPlanId)
            ? $this->activePaymentPlanForUser((int) $setupIntentPaymentPlanId)
            : null;

        if ($paymentPlan !== null) {
            app(UpdatePaymentPlanPaymentMethod::class)->handle($paymentPlan, $setupIntentPaymentMethodId);
        }

        if ($makeDefault) {
            $this->stripeService()->setDefaultPaymentMethod($stripeId, $setupIntentPaymentMethodId);
        }

        $this->setupIntentClientSecret = null;
        $this->paymentMethodTargetPlanId = null;
        $this->refreshBillingContent();

        $notificationTitle = match (true) {
            $paymentPlan !== null && $makeDefault => 'Payment method assigned and made default',
            $paymentPlan !== null => 'Payment method assigned',
            $makeDefault => 'Payment method saved and made default',
            default => 'Payment method saved',
        };

        Notification::make()
            ->title($notificationTitle)
            ->body($paymentPlan !== null
                ? "Future installments for Order #{$paymentPlan->order_id} will use this payment method."
                : ($makeDefault ? 'This does not change payment methods already assigned to payment plans.' : null))
            ->success()
            ->send();

        return $paymentPlan?->id;
    }

    /**
     * @return array<string, string>
     */
    private function paymentMethodOptions(): array
    {
        if ($this->paymentMethods === []) {
            $this->refreshPaymentMethods(notifyOnError: true);
        }

        return collect($this->paymentMethods)
            ->mapWithKeys(fn (array $paymentMethod): array => [
                $paymentMethod['id'] => $this->paymentMethodDescription($paymentMethod)
                    .($paymentMethod['is_default'] ? ' (Account Default)' : ''),
            ])
            ->all();
    }

    private function paymentMethodLabel(?string $paymentMethodId): string
    {
        if ($paymentMethodId === null) {
            $defaultPaymentMethod = collect($this->paymentMethods)
                ->firstWhere('id', $this->defaultPaymentMethodId);

            return is_array($defaultPaymentMethod)
                ? $this->paymentMethodDescription($defaultPaymentMethod).' (Account default fallback)'
                : 'No payment method assigned';
        }

        $paymentMethod = collect($this->paymentMethods)->firstWhere('id', $paymentMethodId);

        return is_array($paymentMethod)
            ? $this->paymentMethodDescription($paymentMethod)
            : 'Assigned payment method unavailable - choose another card';
    }

    /**
     * @param  array{id: string, brand: string, last4: string, expires: string, is_default: bool}  $paymentMethod
     */
    private function paymentMethodDescription(array $paymentMethod): string
    {
        return $paymentMethod['brand'].' ending in '.$paymentMethod['last4'].' Exp '.$paymentMethod['expires'];
    }

    private function availablePaymentMethodId(?string $paymentMethodId): ?string
    {
        if ($paymentMethodId === null) {
            return null;
        }

        return collect($this->paymentMethods)->contains(
            fn (array $paymentMethod): bool => $paymentMethod['id'] === $paymentMethodId
        )
            ? $paymentMethodId
            : null;
    }

    private function paymentMethodExpiration(mixed $paymentMethod): string
    {
        $month = data_get($paymentMethod, 'card.exp_month');
        $year = data_get($paymentMethod, 'card.exp_year');

        if (! is_numeric($month) || ! is_numeric($year)) {
            return '??/??';
        }

        return sprintf('%02d/%02d', (int) $month, (int) $year % 100);
    }

    private function activePaymentPlanForUser(int $paymentPlanId): PaymentPlan
    {
        $paymentPlan = PaymentPlan::query()
            ->whereKey($paymentPlanId)
            ->whereHas('order', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->where('status', '!=', OrderStatus::Cancelled))
            ->whereHas('installments', fn ($query) => $query
                ->where('status', '!=', InstallmentStatus::Paid->value))
            ->first();

        if ($paymentPlan === null) {
            throw new InvalidArgumentException('Active payment plan not found.');
        }

        return $paymentPlan;
    }

    private function stripeService(): StripeServiceContract
    {
        return app(StripeServiceContract::class);
    }

    private function stripeIdForUser(User $user): ?string
    {
        if (array_key_exists('stripe_id', $user->getAttributes())) {
            return $user->stripe_id;
        }

        $stripeId = $user->newQuery()
            ->whereKey($user->id)
            ->value('stripe_id');

        return is_string($stripeId) ? $stripeId : null;
    }
}
