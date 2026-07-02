<?php

declare(strict_types=1);

use App\Filament\Shared\Schemas\OrderSummarySchema;
use App\Models\PaymentPlanTemplate;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

it('evaluates order summary amounts when rendered', function () {
    $subtotal = 5000;
    $total = 5000;

    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '';
        }
    };

    $schema = Schema::make($livewire)
        ->components(OrderSummarySchema::make(
            subtotal: function () use (&$subtotal): int {
                return $subtotal;
            },
            total: function () use (&$total): int {
                return $total;
            },
        ));

    $grid = $schema->getComponents(withHidden: true)[0];
    $rows = $grid->getChildSchema()->getComponents(withHidden: true);

    $subtotalAmount = $rows[0]->getChildSchema()->getComponents(withHidden: true)[1];
    $totalAmount = $rows[13]->getChildSchema()->getComponents(withHidden: true)[1];

    expect($subtotalAmount->getContent())->toBe('$50.00')
        ->and($totalAmount->getContent())->toBe('$50.00');

    $subtotal = 10000;
    $total = 10000;

    expect($subtotalAmount->getContent())->toBe('$100.00')
        ->and($totalAmount->getContent())->toBe('$100.00');
});

it('shows mixed payment plan item amounts when rendered', function () {
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '';
        }
    };

    $schema = Schema::make($livewire)
        ->components(OrderSummarySchema::make(
            subtotal: 8000,
            paymentPlanItemsAmount: 5000,
            paymentPlanFeeAmount: 150,
            payTodayItemsAmount: 3000,
            total: 8150,
            template: $template,
            paymentPlanTotal: 5150,
            amountDueToday: 4289,
        ));

    $grid = $schema->getComponents(withHidden: true)[0];
    $rows = $grid->getChildSchema()->getComponents(withHidden: true);

    $paymentPlanItems = $rows[4]->getChildSchema()->getComponents(withHidden: true);
    $paymentPlanFee = $rows[8]->getChildSchema()->getComponents(withHidden: true);
    $payTodayItems = $rows[9]->getChildSchema()->getComponents(withHidden: true);

    expect($paymentPlanItems[0]->getContent())->toBe('Payment Plan Items')
        ->and($paymentPlanItems[1]->getContent())->toBe('$50.00')
        ->and($paymentPlanFee[0]->getContent())->toBe('Payment Plan Fee (3%)')
        ->and($paymentPlanFee[1]->getContent())->toBe('$1.50')
        ->and($payTodayItems[0]->getContent())->toBe('Pay Today Items')
        ->and($payTodayItems[1]->getContent())->toBe('$30.00');
});

it('shows payment plan reductions in their matching bucket when rendered', function () {
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '';
        }
    };

    $schema = Schema::make($livewire)
        ->components(OrderSummarySchema::make(
            subtotal: 8000,
            discountLabel: 'Discount (SAVE)',
            paymentPlanItemsAmount: 5000,
            paymentPlanDiscountAmount: 1000,
            paymentPlanCreditAmount: 2000,
            paymentPlanFeeAmount: 60,
            payTodayItemsAmount: 3000,
            total: 5060,
            template: $template,
            paymentPlanTotal: 2060,
            amountDueToday: 3515,
        ));

    $grid = $schema->getComponents(withHidden: true)[0];
    $rows = $grid->getChildSchema()->getComponents(withHidden: true);

    $paymentPlanDiscount = $rows[5]->getChildSchema()->getComponents(withHidden: true);
    $paymentPlanCredit = $rows[7]->getChildSchema()->getComponents(withHidden: true);
    $payTodayItems = $rows[9]->getChildSchema()->getComponents(withHidden: true);

    expect($paymentPlanDiscount[0]->getContent())->toBe('Discount (SAVE)')
        ->and($paymentPlanDiscount[1]->getContent())->toBe('-$10.00')
        ->and($paymentPlanCredit[0]->getContent())->toBe('Store Credit')
        ->and($paymentPlanCredit[1]->getContent())->toBe('-$20.00')
        ->and($payTodayItems[0]->getContent())->toBe('Pay Today Items')
        ->and($payTodayItems[1]->getContent())->toBe('$30.00');
});
