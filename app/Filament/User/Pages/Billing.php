<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\SwitchPaymentPlanMethod;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Filament\Actions\RedeemGiftCardAction;
use App\Models\CreditTransaction;
use App\Models\GiftCard;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\RestrictedCredit;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

final class Billing extends Page
{
    public ?string $setupIntentClientSecret = null;

    public ?string $customerSessionClientSecret = null;

    public ?string $defaultPaymentMethodId = null;

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
                        Tab::make('Credits & Gift Cards')
                            ->id('credits')
                            ->schema($this->getCreditsAndGiftCardsSchema()),
                        Tab::make('Payment Methods')
                            ->id('payment-methods')
                            ->schema($this->getPaymentMethodsSchema()),
                    ]),
            ]);
    }

    public function startAddingPaymentMethod(): void
    {
        try {
            /** @var User $user */
            $user = auth()->user();

            $stripeService = $this->stripeService();
            $setupIntent = $stripeService->createSetupIntent($user, [
                'user_id' => (string) $user->id,
            ]);

            $user->refresh();

            $this->setupIntentClientSecret = $setupIntent->client_secret;
            $stripeId = $this->stripeIdForUser($user);
            $this->customerSessionClientSecret = $stripeId !== null
                ? $stripeService->createCustomerSession($stripeId)->client_secret
                : null;
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not start payment method setup')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function paymentMethodSetupCompleted(): void
    {
        $this->setupIntentClientSecret = null;
        $this->customerSessionClientSecret = null;

        $this->refreshPaymentMethods(notifyOnError: true);

        Notification::make()
            ->title('Payment method saved')
            ->success()
            ->send();
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

            PaymentPlan::query()
                ->whereHas('order', fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->where('status', '!=', OrderStatus::Cancelled))
                ->where('method', PaymentPlanMethod::AutoCharge)
                ->whereHas('installments', fn ($query) => $query->where('status', InstallmentStatus::Pending))
                ->update(['stripe_payment_method_id' => $paymentMethodId]);

            $this->refreshPaymentMethods(notifyOnError: true);

            Notification::make()
                ->title('Default payment method updated')
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
            Notification::make()
                ->title('Payment method is in use')
                ->body('Change any active auto-charge payment plans to another saved method before removing this one.')
                ->danger()
                ->send();

            return;
        }

        try {
            $this->stripeService()->detachPaymentMethod($paymentMethodId);
            $this->refreshPaymentMethods(notifyOnError: true);

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
        $creditBalance = (int) User::query()
            ->whereKey(auth()->id())
            ->value('credit_balance');

        $restrictedCreditBalance = (int) RestrictedCredit::query()
            ->where('user_id', auth()->id())
            ->sum('balance');

        $openEnrollments = auth()->user()?->enrollments()->open()->count() ?? 0;

        $nextInstallment = Installment::query()
            ->where('status', InstallmentStatus::Pending)
            ->whereHas('paymentPlan.order', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->where('status', '!=', OrderStatus::Cancelled))
            ->orderBy('due_date')
            ->first();

        return [
            Grid::make()
                ->columns([
                    'default' => 1,
                    'md' => 2,
                    'xl' => 4,
                ])
                ->schema([
                    Section::make('Store Credit')
                        ->schema([
                            TextEntry::make('store_credit_balance')
                                ->hiddenLabel()
                                ->state(format_money($creditBalance))
                        ]),
                    Section::make('Limited Use Credit')
                        ->headerActions([
                            $this->viewLimitedUseCreditDetailsAction(),
                        ])
                        ->schema([
                            TextEntry::make('restricted_credit_balance')
                                ->hiddenLabel()
                                ->state(format_money($restrictedCreditBalance)),
                        ])
                        ->visible($restrictedCreditBalance > 0),
                    Section::make('Next Payment')
                        ->schema([
                            TextEntry::make('next_payment')
                                ->hiddenLabel()
                                ->state($nextInstallment !== null
                                    ? format_money($nextInstallment->amount).' due '.$nextInstallment->due_date->format('M j, Y')
                                    : 'No upcoming payments'),
                        ]),
                    Section::make('Open Seats')
                        ->schema([
                            TextEntry::make('open_enrollments')
                                ->hiddenLabel()
                                ->state((string) $openEnrollments),
                        ]),
                ]),
            Section::make('Recent Orders')
                ->schema($this->getRecentOrdersSchema()),
        ];
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
            ->with(['orderItems.product', 'discountCode'])
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
                                ->dateTime('M j, Y g:i A'),
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

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function receiptSchema(Order $order): array
    {
        $order->loadMissing(['orderItems.product', 'discountCode']);

        $items = $order->orderItems
            ->map(fn (OrderItem $item): TextEntry => TextEntry::make("receipt_item_{$item->id}")
                ->label($item->product->name)
                ->state("Qty {$item->quantity} × {$item->formattedUnitPrice()} = {$item->formattedTotalPrice()}"))
            ->all();

        return [
            Grid::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('receipt_date')
                        ->label('Date')
                        ->state($order->created_at)
                        ->dateTime('M j, Y g:i A'),
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
            ->with(['order', 'template', 'installments'])
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

        return $plans
            ->map(fn (PaymentPlan $plan): Section => Section::make("Order #{$plan->order_id}")
                ->schema([
                    Grid::make()
                        ->columns([
                            'default' => 1,
                            'md' => 4,
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
                            TextEntry::make("plan_{$plan->id}_method")
                                ->label('Method')
                                ->state($plan->method)
                                ->badge(),
                        ]),
                    Actions::make([
                        $this->switchPaymentPlanMethodAction($plan),
                        $this->installmentsAction($plan),
                    ]),
                ])
                ->compact())
            ->all();
    }

    private function switchPaymentPlanMethodAction(PaymentPlan $plan): Action
    {
        return Action::make("switch_plan_method_{$plan->id}")
            ->label('Update Method')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (): bool => ! $plan->isFullyPaid())
            ->schema([
                Select::make('method')
                    ->label('Payment Plan Method')
                    ->options(PaymentPlanMethod::class)
                    ->default($plan->method->value)
                    ->required()
                    ->live(),
                Select::make('stripe_payment_method_id')
                    ->label('Saved Payment Method')
                    ->options(fn (): array => $this->paymentMethodOptions())
                    ->default($plan->stripe_payment_method_id)
                    ->required(fn (Get $get): bool => $get('method') === PaymentPlanMethod::AutoCharge->value)
                    ->visible(fn (Get $get): bool => $get('method') === PaymentPlanMethod::AutoCharge->value),
            ])
            ->action(function (array $data) use ($plan): void {
                try {
                    app(SwitchPaymentPlanMethod::class)->handle(
                        $plan,
                        PaymentPlanMethod::from($data['method']),
                        $data['stripe_payment_method_id'] ?? null,
                    );

                    Notification::make()
                        ->title('Payment plan updated')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not update payment plan')
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
                                        ->state($installment->status)
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
        $creditBalance = (int) User::query()
            ->whereKey(auth()->id())
            ->value('credit_balance');

        $restrictedCredits = RestrictedCredit::query()
            ->where('user_id', auth()->id())
            ->where('balance', '>', 0)
            ->with(['giftCardType', 'giftCard'])
            ->get();

        $restrictedCreditBalance = (int) $restrictedCredits->sum('balance');

        return [
            Actions::make([
                RedeemGiftCardAction::make(),
            ]),
            Grid::make()
                ->columns([
                    'default' => 1,
                    'md' => 2,
                ])
                ->schema([
                    Section::make('Store Credit')
                        ->schema([
                            TextEntry::make('credits_store_credit')
                                ->hiddenLabel()
                                ->state(format_money($creditBalance)),
                        ]),
                    Section::make('Limited Use Credit')
                        ->schema([
                            TextEntry::make('credits_restricted_credit')
                                ->hiddenLabel()
                                ->state(format_money($restrictedCreditBalance)),
                        ])
                        ->visible($restrictedCreditBalance > 0),
                ]),
            Section::make('Limited Use Credit Details')
                ->schema($this->restrictedCreditSchema($restrictedCredits))
                ->extraAttributes([
                    'id' => 'limited-use-credit-details',
                    'tabindex' => '-1',
                ])
                ->visible($restrictedCreditBalance > 0),
            Section::make('Credit Activity')
                ->schema($this->creditTransactionSchema()),
            Section::make('Unredeemed Gift Cards')
                ->schema($this->giftCardSchema(redeemed: false)),
            Section::make('Redeemed Gift Cards')
                ->schema($this->giftCardSchema(redeemed: true)),
        ];
    }

    /**
     * @param  EloquentCollection<int, RestrictedCredit>  $restrictedCredits
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function restrictedCreditSchema(EloquentCollection $restrictedCredits): array
    {
        if ($restrictedCredits->isEmpty()) {
            return [
                TextEntry::make('restricted_credit_empty')
                    ->hiddenLabel()
                    ->state('No limited use credit balances.'),
            ];
        }

        return $restrictedCredits
            ->map(fn (RestrictedCredit $credit): TextEntry => TextEntry::make("restricted_credit_{$credit->id}")
                ->label($credit->giftCardType?->restrictionSummary() ?? 'Limited Use Credit')
                ->state($credit->formattedBalance()))
            ->all();
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function creditTransactionSchema(): array
    {
        $transactions = CreditTransaction::query()
            ->where('user_id', auth()->id())
            ->where(function ($query): void {
                $query
                    ->whereNull('reference_type')
                    ->orWhere('reference_type', '!=', (new Order)->getMorphClass())
                    ->orWhereHasMorph('reference', [Order::class], fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled));
            })
            ->latest()
            ->limit(25)
            ->get();

        if ($transactions->isEmpty()) {
            return [
                TextEntry::make('credit_transactions_empty')
                    ->hiddenLabel()
                    ->state('No credit activity yet.'),
            ];
        }

        return $transactions
            ->map(fn (CreditTransaction $transaction): TextEntry => TextEntry::make("credit_transaction_{$transaction->id}")
                ->label($transaction->created_at->format('M j, Y'))
                ->state(function () use ($transaction): string {
                    $description = $transaction->description === null
                        ? ''
                        : ' · '.str_replace(
                            ['Restricted Credit', 'restricted credit'],
                            ['Limited Use Credit', 'limited use credit'],
                            $transaction->description,
                        );

                    return $transaction->formattedAmount().' · '.$transaction->type->getLabel().$description;
                }))
            ->all();
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
        $components = [
            Actions::make([
                Action::make('addPaymentMethod')
                    ->label('Add Payment Method')
                    ->icon(Heroicon::OutlinedPlus)
                    ->action('startAddingPaymentMethod'),
            ]),
            View::make('filament.user.pages.billing-payment-methods')
                ->visible(fn (): bool => $this->setupIntentClientSecret !== null),
        ];

        if ($this->paymentMethods === []) {
            $components[] = Section::make('Saved Payment Methods')
                ->schema([
                    TextEntry::make('payment_methods_empty')
                        ->hiddenLabel()
                        ->state('No saved payment methods.'),
                ]);

            return $components;
        }

        $components[] = Section::make('Saved Payment Methods')
            ->schema(
                collect($this->paymentMethods)
                    ->map(fn (array $paymentMethod): Section => Section::make($paymentMethod['brand'].' ending in '.$paymentMethod['last4'])
                        ->schema([
                            Grid::make()
                                ->columns([
                                    'default' => 1,
                                    'md' => 3,
                                ])
                                ->schema([
                                    TextEntry::make("payment_method_{$paymentMethod['id']}_brand")
                                        ->label('Brand')
                                        ->state($paymentMethod['brand']),
                                    TextEntry::make("payment_method_{$paymentMethod['id']}_last4")
                                        ->label('Last 4')
                                        ->state($paymentMethod['last4']),
                                    TextEntry::make("payment_method_{$paymentMethod['id']}_expires")
                                        ->label('Expires')
                                        ->state($paymentMethod['expires']),
                                ]),
                            Actions::make([
                                Action::make("default_payment_method_{$paymentMethod['id']}")
                                    ->label('Make Default')
                                    ->icon(Heroicon::OutlinedStar)
                                    ->visible(! $paymentMethod['is_default'])
                                    ->action(fn (): mixed => $this->makeDefaultPaymentMethod($paymentMethod['id'])),
                                Action::make("remove_payment_method_{$paymentMethod['id']}")
                                    ->label('Remove')
                                    ->icon(Heroicon::OutlinedTrash)
                                    ->color('danger')
                                    ->requiresConfirmation()
                                    ->action(fn (): mixed => $this->removePaymentMethod($paymentMethod['id'])),
                            ]),
                        ])
                        ->compact())
                    ->all()
            );

        return $components;
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
                    'expires' => data_get($paymentMethod, 'card.exp_month', '??').'/'.data_get($paymentMethod, 'card.exp_year', '????'),
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

    private function paymentMethodBelongsToUser(string $paymentMethodId): bool
    {
        if ($this->paymentMethods === []) {
            $this->refreshPaymentMethods(notifyOnError: true);
        }

        return collect($this->paymentMethods)
            ->contains(fn (array $paymentMethod): bool => $paymentMethod['id'] === $paymentMethodId);
    }

    private function activeAutoChargePlansUsing(string $paymentMethodId): int
    {
        return PaymentPlan::query()
            ->whereHas('order', fn ($query) => $query
                ->where('user_id', auth()->id())
                ->where('status', '!=', OrderStatus::Cancelled))
            ->where('method', PaymentPlanMethod::AutoCharge)
            ->where('stripe_payment_method_id', $paymentMethodId)
            ->whereHas('installments', fn ($query) => $query->where('status', InstallmentStatus::Pending))
            ->count();
    }

    private function viewLimitedUseCreditDetailsAction(): Action
    {
        return Action::make('viewLimitedUseCreditDetails')
            ->label('View Details')
            ->icon(Heroicon::OutlinedArrowRight)
            ->url(fn (): string => self::getUrl(['tab' => 'credits']).'#limited-use-credit-details');
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
                $paymentMethod['id'] => $paymentMethod['brand'].' ending in '.$paymentMethod['last4'],
            ])
            ->all();
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
