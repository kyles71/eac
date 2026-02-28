<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\ConfirmCheckoutPayment;
use App\Actions\Store\CreateCheckoutPaymentIntent;
use App\Enums\PaymentPlanMethod;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Url;

final class Checkout extends Page
{
    protected static ?string $title = 'Checkout';

    protected static ?string $slug = 'checkout';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static bool $shouldRegisterNavigation = false;

    public int $currentStep = 1;

    public ?string $clientSecret = null;

    public ?int $orderId = null;

    public ?string $paymentMethodId = null;

    public ?string $cardBrand = null;

    public ?string $cardLast4 = null;

    /** @var array<int, array{name: string, quantity: int, unit_price: int, total_price: int}> */
    public array $cartSummary = [];

    public int $subtotal = 0;

    public int $total = 0;

    public int $checkoutAmount = 0;

    public ?string $discountDisplay = null;

    public ?string $creditDisplay = null;

    public ?string $paymentPlanDisplay = null;

    #[Url]
    public ?int $discountCodeId = null;

    #[Url(as: 'use_credit')]
    public bool $useCredit = false;

    #[Url(as: 'payment_plan_template_id')]
    public ?int $paymentPlanTemplateId = null;

    #[Url(as: 'payment_plan_method')]
    public ?string $paymentPlanMethodValue = null;

    public function mount(): void
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $discountCode = $this->discountCodeId !== null
            ? DiscountCode::query()->find($this->discountCodeId)
            : null;

        $creditToApply = $this->useCredit ? ($user->credit_balance ?? 0) : 0;

        $paymentPlanTemplate = $this->paymentPlanTemplateId !== null
            ? PaymentPlanTemplate::query()->find($this->paymentPlanTemplateId)
            : null;

        $paymentPlanMethod = $this->paymentPlanMethodValue !== null
            ? PaymentPlanMethod::from($this->paymentPlanMethodValue)
            : null;

        try {
            $action = app(CreateCheckoutPaymentIntent::class);
            $result = $action->handle(
                $user,
                $discountCode,
                $creditToApply,
                $paymentPlanTemplate,
                $paymentPlanMethod,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Checkout failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->redirect(Cart::getUrl());

            return;
        }

        if ($result['zero_total']) {
            $this->redirect(CheckoutSuccess::getUrl().'?order_id='.$result['order_id']);

            return;
        }

        $this->clientSecret = $result['client_secret'];
        $this->orderId = $result['order_id'];
        $this->cartSummary = $result['cart_summary'];
        $this->subtotal = $result['subtotal'];
        $this->total = $result['total'];
        $this->checkoutAmount = $result['checkout_amount'];
        $this->discountDisplay = $result['discount_display'];
        $this->creditDisplay = $result['credit_display'];
        $this->paymentPlanDisplay = $result['payment_plan_display'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Payment Details')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->schema($this->paymentStepSchema()),
                Step::make('Confirmation')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->schema($this->confirmationStepSchema()),
            ])
                ->startOnStep($this->currentStep)
                ->skippable($this->currentStep > 1)
                ->contained(false),
        ]);
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function paymentStepSchema(): array
    {
        $components = [];

        // Order summary section
        $summaryEntries = [];

        foreach ($this->cartSummary as $index => $item) {
            $summaryEntries[] = TextEntry::make("cart_item_{$index}")
                ->label($item['name'].' × '.$item['quantity'])
                ->state(fn (): string => $this->formatCents($item['total_price']));
        }

        $summaryEntries[] = TextEntry::make('subtotal')
            ->label('Subtotal')
            ->state(fn (): string => $this->formatCents($this->subtotal))
            ->weight('bold');

        if ($this->discountDisplay !== null) {
            $summaryEntries[] = TextEntry::make('discount')
                ->label('Discount')
                ->state($this->discountDisplay)
                ->color('success');
        }

        if ($this->creditDisplay !== null) {
            $summaryEntries[] = TextEntry::make('credit')
                ->label('Store Credit')
                ->state($this->creditDisplay)
                ->color('success');
        }

        if ($this->paymentPlanDisplay !== null) {
            $summaryEntries[] = TextEntry::make('payment_plan')
                ->label('Payment Plan')
                ->state($this->paymentPlanDisplay)
                ->color('info');
        }

        $summaryEntries[] = TextEntry::make('amount_due')
            ->label('Amount Due Now')
            ->state(fn (): string => $this->formatCents($this->checkoutAmount))
            ->weight('bold')
            ->size(TextSize::Large);

        $components[] = Section::make('Order Summary')
            ->schema($summaryEntries)
            ->collapsible();

        // Stripe Elements
        $components[] = Section::make('Payment Information')
            ->schema([
                View::make('filament.user.pages.checkout.stripe-payment-element')
                    ->viewData([
                        'clientSecret' => $this->clientSecret,
                        'stripeKey' => $this->getStripeKey(),
                    ]),
            ]);

        return $components;
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function confirmationStepSchema(): array
    {
        $components = [];

        // Success banner
        $components[] = Section::make('Payment Successful!')
            ->description('Your order has been confirmed.')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->schema([]);

        // Order details
        $orderEntries = [];

        foreach ($this->cartSummary as $index => $item) {
            $orderEntries[] = TextEntry::make("confirm_item_{$index}")
                ->label($item['name'].' × '.$item['quantity'])
                ->state(fn (): string => $this->formatCents($item['total_price']));
        }

        if ($this->discountDisplay !== null) {
            $orderEntries[] = TextEntry::make('confirm_discount')
                ->label('Discount')
                ->state($this->discountDisplay)
                ->color('success');
        }

        if ($this->creditDisplay !== null) {
            $orderEntries[] = TextEntry::make('confirm_credit')
                ->label('Store Credit')
                ->state($this->creditDisplay)
                ->color('success');
        }

        $orderEntries[] = TextEntry::make('confirm_total')
            ->label('Total Charged')
            ->state(fn (): string => $this->formatCents($this->checkoutAmount))
            ->weight('bold')
            ->size(TextSize::Large);

        $components[] = Section::make('Order Summary')
            ->schema($orderEntries);

        // Payment method
        $components[] = Section::make('Payment Method')
            ->schema([
                TextEntry::make('card_info')
                    ->label('Card')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->state(fn (): string => ucfirst($this->cardBrand ?? 'Card').' ending in '.($this->cardLast4 ?? '****')),
            ]);

        // Payment plan info
        if ($this->paymentPlanDisplay !== null) {
            $components[] = Section::make('Payment Plan')
                ->schema([
                    TextEntry::make('confirm_plan')
                        ->label('Plan')
                        ->state($this->paymentPlanDisplay)
                        ->color('info'),
                    TextEntry::make('confirm_plan_note')
                        ->hiddenLabel()
                        ->state('Remaining installments will be charged according to your plan schedule.'),
                ]);
        }

        return $components;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToCart')
                ->label('Back to Cart')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Cart::getUrl())
                ->visible(fn (): bool => $this->currentStep === 1),
            Action::make('viewEnrollments')
                ->label('View My Classes')
                ->icon(Heroicon::OutlinedAcademicCap)
                ->url(MyEnrollments::getUrl())
                ->visible(fn (): bool => $this->currentStep === 2),
            Action::make('continueShopping')
                ->label('Continue Shopping')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->url(Store::getUrl())
                ->visible(fn (): bool => $this->currentStep === 2),
        ];
    }

    public function setPaymentMethod(string $paymentMethodId, string $brand, string $last4): void
    {
        $this->paymentMethodId = $paymentMethodId;
        $this->cardBrand = $brand;
        $this->cardLast4 = $last4;
        $this->currentStep = 2;
    }

    public function goBackToPayment(): void
    {
        $this->currentStep = 1;
    }

    public function paymentConfirmed(): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            Notification::make()
                ->title('Order not found')
                ->danger()
                ->send();

            return;
        }

        $paymentPlanTemplate = $this->paymentPlanTemplateId !== null
            ? PaymentPlanTemplate::query()->find($this->paymentPlanTemplateId)
            : null;

        $paymentPlanMethod = $this->paymentPlanMethodValue !== null
            ? PaymentPlanMethod::from($this->paymentPlanMethodValue)
            : null;

        try {
            $confirmPayment = app(ConfirmCheckoutPayment::class);
            $confirmPayment->handle($order, $paymentPlanTemplate, $paymentPlanMethod);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Payment confirmation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->redirect(CheckoutSuccess::getUrl().'?order_id='.$this->orderId);
    }

    public function getStripeKey(): string
    {
        return (string) config('services.stripe.key');
    }

    public function formatCents(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
