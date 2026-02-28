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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Url;

final class Checkout extends Page
{
    protected string $view = 'filament.user.pages.checkout';

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
