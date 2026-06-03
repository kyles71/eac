<?php

declare(strict_types=1);

use App\Filament\Shared\Schemas\OrderSummarySchema;
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
    $totalAmount = $rows[5]->getChildSchema()->getComponents(withHidden: true)[1];

    expect($subtotalAmount->getContent())->toBe('$50.00')
        ->and($totalAmount->getContent())->toBe('$50.00');

    $subtotal = 10000;
    $total = 10000;

    expect($subtotalAmount->getContent())->toBe('$100.00')
        ->and($totalAmount->getContent())->toBe('$100.00');
});
