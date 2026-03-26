<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Models\PaymentPlanTemplate;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontWeight;

final class OrderSummarySchema
{
    /**
     * Build the order summary line items (Subtotal → Discounts → Credits → Total → Payment Plan).
     *
     * @return array<Component>
     */
    public static function make(
        int $subtotal,
        int $discountAmount = 0,
        ?string $discountLabel = null,
        int $restrictedCreditAmount = 0,
        int $creditAmount = 0,
        int $total = 0,
        ?PaymentPlanTemplate $template = null,
        ?int $amountDueToday = null,
    ): array {
        $totalComponents = [];

        $totalComponents[] = Flex::make([
            Text::make('Subtotal')
                ->color('neutral')
                ->columnSpanFull(),
            Text::make(format_money($subtotal))
                ->color('neutral')
                ->grow(false),
        ]);

        if ($discountAmount > 0) {
            $totalComponents[] = Flex::make([
                Text::make($discountLabel ?? 'Discount')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make('-'.format_money($discountAmount))
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($restrictedCreditAmount > 0) {
            $totalComponents[] = Flex::make([
                Text::make('Restricted Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make('-'.format_money($restrictedCreditAmount))
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        if ($creditAmount > 0) {
            $totalComponents[] = Flex::make([
                Text::make('Store Credit')
                    ->color('danger')
                    ->columnSpanFull(),
                Text::make('-'.format_money($creditAmount))
                    ->color('danger')
                    ->grow(false),
            ]);
        }

        $totalComponents[] = Flex::make([
            Text::make('Total')
                ->size('md')
                ->weight(FontWeight::Bold)
                ->columnSpanFull(),
            Text::make(format_money($total))
                ->size('md')
                ->weight(FontWeight::Bold)
                ->grow(false),
        ])
            ->extraAttributes(['class' => 'border-t border-gray-300 pt-2']);

        if ($template !== null) {
            $amounts = $template->installmentAmounts($total);
            $dueToday = $amountDueToday ?? $amounts['first'];

            $totalComponents[] = Text::make("{$template->number_of_installments} payments of ".format_money($amounts['remaining']))
                ->color('neutral')
                ->extraAttributes(['class' => 'border-t border-gray-300 pt-2 w-full']);

            $totalComponents[] = Flex::make([
                Text::make('Amount Due Today')
                    ->weight(FontWeight::Bold)
                    ->columnSpanFull(),
                Text::make(format_money($dueToday))
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
}
