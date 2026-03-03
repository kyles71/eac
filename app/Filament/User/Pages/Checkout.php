<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Order;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

final class Checkout extends Page
{
    public ?Order $order = null;

    public ?string $clientSecret = null;

    public bool $savePaymentMethod = true;

    public ?string $selectedSavedPaymentMethod = null;

    /** @var array<int, array{id: string, brand: string, last4: string, exp_month: int, exp_year: int}> */
    public array $savedPaymentMethods = [];

    protected static ?string $title = 'Checkout';

    protected static ?string $slug = 'checkout';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $orderId = request()->query('order_id');

        if ($orderId === null) {
            $this->redirect(Cart::getUrl());

            return;
        }

        $this->order = Order::query()
            ->where('user_id', auth()->id())
            ->where('id', $orderId)
            ->where('status', OrderStatus::Pending)
            ->with(['orderItems.product', 'discountCode', 'paymentPlanTemplate'])
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
        $this->loadSavedPaymentMethods();
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

    /**
     * Confirm payment using a saved payment method (server-side).
     */
    public function confirmWithSavedMethod(): void
    {
        if ($this->order === null || $this->selectedSavedPaymentMethod === null) {
            Notification::make()
                ->title('Payment failed')
                ->body('Please select a payment method.')
                ->danger()
                ->send();

            return;
        }

        try {
            /** @var StripeServiceContract $stripeService */
            $stripeService = app(StripeServiceContract::class);

            $stripeService->confirmPaymentIntent(
                $this->order->stripe_payment_intent_id,
                $this->selectedSavedPaymentMethod,
            );

            if ($this->savePaymentMethod) {
                /** @var \App\Models\User $user */
                $user = auth()->user();
                $user->update(['stripe_payment_method_id' => $this->selectedSavedPaymentMethod]);
            }

            $this->redirect(CheckoutSuccess::getUrl().'?order_id='.$this->order->id);
        } catch (Exception $e) {
            Notification::make()
                ->title('Payment failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
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

    /**
     * Format cents as dollars.
     */
    private function formatMoney(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    private function createPaymentIntent(): void
    {
        /** @var StripeServiceContract $stripeService */
        $stripeService = app(StripeServiceContract::class);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Determine charge amount (first installment if payment plan, else full total)
        $chargeAmount = $this->order->total;
        $template = $this->order->paymentPlanTemplate;

        if ($template !== null) {
            $amounts = $template->installmentAmounts($this->order->total);
            $chargeAmount = $amounts['first'];
        }

        $metadata = [
            'order_id' => (string) $this->order->id,
        ];

        $usePaymentPlan = $template !== null && $this->order->payment_plan_method !== null;

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

    private function loadSavedPaymentMethods(): void
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($user->stripe_id === null) {
            return;
        }

        /** @var StripeServiceContract $stripeService */
        $stripeService = app(StripeServiceContract::class);

        $methods = $stripeService->getPaymentMethods($user->stripe_id);

        $this->savedPaymentMethods = $methods->map(fn ($method): array => [
            'id' => $method->id,
            'brand' => ucfirst($method->card->brand),
            'last4' => $method->card->last4,
            'exp_month' => $method->card->exp_month,
            'exp_year' => $method->card->exp_year,
        ])->all();
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

            $rows[] = Flex::make([
                Text::make($product->name)
                    ->columnSpanFull(),
                Text::make("Qty: {$item->quantity}")
                    ->color('neutral')
                    ->grow(false),
                Text::make($this->formatMoney($item->total_price))
                    ->grow(false),
            ]);
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
                Text::make("-{$this->formatMoney($this->order->discount_amount)}")
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($this->order->restricted_credit_applied > 0) {
            $components[] = Flex::make([
                Text::make('Restricted Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make("-{$this->formatMoney($this->order->restricted_credit_applied)}")
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($this->order->credit_applied > 0) {
            $components[] = Flex::make([
                Text::make('Store Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make("-{$this->formatMoney($this->order->credit_applied)}")
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
        $totalComponents = [];

        $totalComponents[] = Flex::make([
            Text::make('Subtotal')
                ->color('neutral')
                ->columnSpanFull(),
            Text::make($this->formatMoney($this->order->subtotal))
                ->color('neutral')
                ->grow(false),
        ]);

        if ($this->order->discount_amount > 0) {
            $totalComponents[] = Flex::make([
                Text::make('Discount')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make("-{$this->formatMoney($this->order->discount_amount)}")
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($this->order->restricted_credit_applied > 0) {
            $totalComponents[] = Flex::make([
                Text::make('Restricted Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make("-{$this->formatMoney($this->order->restricted_credit_applied)}")
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($this->order->credit_applied > 0) {
            $totalComponents[] = Flex::make([
                Text::make('Store Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make("-{$this->formatMoney($this->order->credit_applied)}")
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        $totalComponents[] = Flex::make([
            Text::make('Total')
                ->size('md')
                ->weight(FontWeight::Bold)
                ->columnSpanFull(),
            Text::make($this->formatMoney($this->order->total))
                ->size('md')
                ->weight(FontWeight::Bold)
                ->grow(false),
        ])
            ->extraAttributes(['class' => 'border-t border-gray-300 pt-2']);

        $template = $this->order->paymentPlanTemplate;

        if ($template !== null) {
            $amounts = $template->installmentAmounts($this->order->total);

            $totalComponents[] = Text::make("{$template->number_of_installments} payments of {$this->formatMoney($amounts['remaining'])}")
                ->color('neutral')
                ->extraAttributes(['class' => 'border-t border-gray-300 pt-2 w-full']);

            $totalComponents[] = Flex::make([
                Text::make('Amount Due Today')
                    ->weight(FontWeight::Bold)
                    ->columnSpanFull(),
                Text::make($this->formatMoney($amounts['first']))
                    ->weight(FontWeight::Bold)
                    ->grow(false),
            ]);
        }

        return [
            Grid::make(1)
                ->schema($totalComponents)
                ->gap(false),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getPaymentSchema(): array
    {
        $components = [];

        if (! empty($this->savedPaymentMethods)) {
            $options = [];
            foreach ($this->savedPaymentMethods as $method) {
                $options[$method['id']] = "{$method['brand']} ending in {$method['last4']} (expires {$method['exp_month']}/{$method['exp_year']})";
            }
            $options['new'] = 'Use a new card';

            $components[] = Radio::make('selectedSavedPaymentMethod')
                ->label('Payment Method')
                ->options($options)
                ->default('new')
                ->live();
        }

        $components[] = Checkbox::make('savePaymentMethod')
            ->label('Save this payment method for future use')
            ->default(true);

        $components[] = View::make('filament.user.pages.checkout-payment');

        return $components;
    }
}
