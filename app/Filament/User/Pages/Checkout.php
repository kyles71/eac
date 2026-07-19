<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Filament\Shared\Schemas\OrderSummarySchema;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Services\ProductQuestionAnswerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class Checkout extends Page
{
    public ?Order $order = null;

    public ?string $clientSecret = null;

    public ?string $customerSessionClientSecret = null;

    protected static ?string $title = 'Checkout';

    protected static ?string $slug = 'checkout';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $this->order = Order::query()
            ->where('user_id', auth()->id())
            ->where('status', OrderStatus::Pending)
            ->with(['orderItems.product', 'orderItems.questionAnswers', 'discountCode', 'paymentPlanTemplate'])
            ->latest()
            ->first();

        if ($this->order === null) {
            Notification::make()
                ->title('Order not found')
                ->body('The order could not be found or has already been processed.')
                ->danger()
                ->send();

            $this->redirect(Cart::getUrl());

            return;
        }

        $this->createPaymentIntent();
        $this->createCustomerSession();
    }

    /**
     * Mark the order as processing before Stripe payment confirmation.
     */
    public function markOrderProcessing(): void
    {
        if ($this->order !== null && $this->order->status === OrderStatus::Pending) {
            $this->order->update(['status' => OrderStatus::Processing]);
        }
    }

    /**
     * Revert the order to pending if Stripe returns a client-side error.
     */
    public function revertOrderToPending(): void
    {
        if ($this->order !== null && $this->order->status === OrderStatus::Processing) {
            $this->order->update(['status' => OrderStatus::Pending]);
        }
    }

    public function content(Schema $schema): Schema
    {
        if ($this->order === null) {
            return $schema->components([]);
        }

        return $schema
            ->components([
                Section::make('Your Cart')
                    ->schema($this->getCartItemsSchema())
                    ->columnSpanFull(),
                Flex::make([
                    Section::make('Discounts & Credits')
                        ->schema($this->getDiscountsSchema())
                        ->grow(false)
                        ->columnSpanFull()
                        ->visible(fn (): bool => $this->order->discount_amount > 0
                            || $this->order->restricted_credit_applied > 0
                            || $this->order->credit_applied > 0),
                    Text::make('')
                        ->columnSpanFull(),
                    Section::make('Order Summary')
                        ->schema($this->getOrderSummarySchema())
                        ->grow(false)
                        ->columnSpanFull(),
                ])
                    ->from('lg'),
                Section::make('Payment')
                    ->schema($this->getPaymentSchema()),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCart')
                ->label('Back to Cart')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Cart::getUrl()),
        ];
    }

    private function createPaymentIntent(): void
    {
        /** @var StripeServiceContract $stripeService */
        $stripeService = app(StripeServiceContract::class);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        $template = $this->order->paymentPlanTemplate;
        $chargeAmount = $this->order->amountPaidAtCheckout();

        $metadata = [
            'order_id' => (string) $this->order->id,
        ];

        $usePaymentPlan = $template !== null;

        $paymentIntent = $stripeService->createPaymentIntent(
            user: $user,
            amount: $chargeAmount,
            metadata: $metadata,
            setupFutureUsage: $usePaymentPlan,
        );

        $this->order->update([
            'stripe_payment_intent_id' => $paymentIntent->id,
        ]);

        $this->clientSecret = $paymentIntent->client_secret;
    }

    private function createCustomerSession(): void
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->stripe_id === null) {
            return;
        }

        /** @var StripeServiceContract $stripeService */
        $stripeService = app(StripeServiceContract::class);

        $this->makePlanPaymentMethodsRedisplayable($stripeService, $user->stripe_id);

        $customerSession = $stripeService->createCustomerSession(
            customerId: $user->stripe_id,
            allowPaymentMethodSave: $this->order?->paymentPlanTemplate === null,
        );

        $this->customerSessionClientSecret = $customerSession->client_secret;
    }

    private function makePlanPaymentMethodsRedisplayable(StripeServiceContract $stripeService, string $customerId): void
    {
        $assignedPaymentMethodIds = PaymentPlan::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', auth()->id()))
            ->whereNotNull('stripe_payment_method_id')
            ->pluck('stripe_payment_method_id')
            ->unique()
            ->all();

        if ($assignedPaymentMethodIds === []) {
            return;
        }

        $paymentMethodIdsToUpdate = collect($stripeService->listPaymentMethods($customerId))
            ->filter(fn (mixed $paymentMethod): bool => data_get($paymentMethod, 'allow_redisplay') !== 'always')
            ->pluck('id')
            ->filter(fn (mixed $paymentMethodId): bool => is_string($paymentMethodId))
            ->all();

        foreach (array_intersect($assignedPaymentMethodIds, $paymentMethodIdsToUpdate) as $paymentMethodId) {
            $stripeService->makePaymentMethodRedisplayable($paymentMethodId);
        }
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getCartItemsSchema(): array
    {
        $rows = [];

        foreach ($this->order->orderItems as $item) {
            /** @var \App\Models\OrderItem $item */
            /** @var \App\Models\Product $product */
            $product = $item->product;
            $displayUnits = app(ProductQuestionAnswerService::class)->orderDisplayUnits($item);

            $components = [
                Text::make($product->name)
                    ->columnSpanFull(),
            ];

            if ($displayUnits !== []) {
                $components[] = View::make('filament.shared.product-question-details')
                    ->viewData([
                        'units' => $displayUnits,
                    ])
                    ->key("order-item-{$item->id}-details")
                    ->columnSpanFull();
            }

            $components[] = Text::make("Qty: {$item->quantity}")
                ->color('neutral')
                ->grow(false);
            $components[] = Text::make(format_money($item->total_price))
                ->grow(false);

            $rows[] = Flex::make($components);
        }

        return [
            Grid::make(1)
                ->schema($rows)
                ->gap(false),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getDiscountsSchema(): array
    {
        $components = [];

        if ($this->order->discount_amount > 0) {
            $discountLabel = $this->order->discountCode !== null
                ? "Discount ({$this->order->discountCode->code})"
                : 'Discount';

            $components[] = Flex::make([
                Text::make($discountLabel)
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make('-'.format_money($this->order->discount_amount))
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($this->order->restricted_credit_applied > 0) {
            $components[] = Flex::make([
                Text::make('Limited Use Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make('-'.format_money($this->order->restricted_credit_applied))
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($this->order->credit_applied > 0) {
            $components[] = Flex::make([
                Text::make('Store Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make('-'.format_money($this->order->credit_applied))
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        return $components;
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getOrderSummarySchema(): array
    {
        $discountLabel = null;

        if ($this->order->discount_amount > 0 && $this->order->discountCode !== null) {
            $discountLabel = "Discount ({$this->order->discountCode->code})";
        }

        return OrderSummarySchema::make(
            subtotal: $this->order->subtotal,
            discountAmount: $this->order->discount_amount,
            discountLabel: $discountLabel,
            restrictedCreditAmount: $this->order->restricted_credit_applied,
            creditAmount: $this->order->credit_applied,
            paymentPlanItemsAmount: $this->order->paymentPlanItemsSubtotal(),
            paymentPlanDiscountAmount: $this->order->payment_plan_discount_amount,
            paymentPlanRestrictedCreditAmount: $this->order->payment_plan_restricted_credit_applied,
            paymentPlanCreditAmount: $this->order->payment_plan_credit_applied,
            payTodayItemsAmount: $this->order->paymentPlanTemplate !== null ? $this->order->payInFullItemsSubtotal() : null,
            payTodayDiscountAmount: $this->order->payInFullDiscountAmount(),
            payTodayRestrictedCreditAmount: $this->order->payInFullRestrictedCreditAmount(),
            payTodayCreditAmount: $this->order->payInFullCreditAmount(),
            paymentPlanFeeAmount: $this->order->payment_plan_fee,
            total: $this->order->total,
            template: $this->order->paymentPlanTemplate,
            paymentPlanTotal: $this->order->paymentPlanInstallmentTotal(),
            amountDueToday: $this->order->paymentPlanTemplate !== null ? $this->order->amountPaidAtCheckout() : null,
        );
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getPaymentSchema(): array
    {
        return [
            Text::make('Because this order uses a payment plan, the payment method used today will be securely saved in Stripe for future installments and purchases.')
                ->color('neutral')
                ->visible($this->order->paymentPlanTemplate !== null),
            View::make('filament.user.pages.checkout-payment'),
        ];
    }
}
