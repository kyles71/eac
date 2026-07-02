<?php

declare(strict_types=1);

namespace App\Filament\Shared\Schemas;

use App\Models\PaymentPlanTemplate;
use App\Support\PaymentPlanFee;
use Closure;
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
        int|Closure $subtotal,
        int|Closure $discountAmount = 0,
        string|Closure|null $discountLabel = null,
        int|Closure $restrictedCreditAmount = 0,
        int|Closure $creditAmount = 0,
        int|Closure|null $paymentPlanItemsAmount = null,
        int|Closure $paymentPlanDiscountAmount = 0,
        int|Closure $paymentPlanRestrictedCreditAmount = 0,
        int|Closure $paymentPlanCreditAmount = 0,
        int|Closure|null $payTodayItemsAmount = null,
        int|Closure $payTodayDiscountAmount = 0,
        int|Closure $payTodayRestrictedCreditAmount = 0,
        int|Closure $payTodayCreditAmount = 0,
        int|Closure $paymentPlanFeeAmount = 0,
        int|Closure $total = 0,
        PaymentPlanTemplate|Closure|null $template = null,
        int|Closure|null $paymentPlanTotal = null,
        int|Closure|null $amountDueToday = null,
    ): array {
        $totalComponents = [];

        $totalComponents[] = Flex::make([
            Text::make('Subtotal')
                ->color('neutral')
                ->columnSpanFull(),
            Text::make(fn (): string => format_money(self::amount($subtotal)))
                ->color('neutral')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) === null);

        $totalComponents[] = Flex::make([
            Text::make(function () use ($discountLabel): string {
                $label = value($discountLabel);

                return is_string($label) && $label !== '' ? $label : 'Discount';
            })
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($discountAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) === null && self::amount($discountAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Limited Use Credit')
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($restrictedCreditAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) === null && self::amount($restrictedCreditAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Store Credit')
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($creditAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) === null && self::amount($creditAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Payment Plan Items')
                ->color('neutral')
                ->columnSpanFull(),
            Text::make(fn (): string => format_money(self::nullableAmount($paymentPlanItemsAmount) ?? 0))
                ->color('neutral')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && (self::nullableAmount($paymentPlanItemsAmount) ?? 0) > 0);

        $totalComponents[] = Flex::make([
            Text::make(function () use ($discountLabel): string {
                $label = value($discountLabel);

                return is_string($label) && $label !== '' ? $label : 'Discount';
            })
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($paymentPlanDiscountAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && self::amount($paymentPlanDiscountAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Limited Use Credit')
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($paymentPlanRestrictedCreditAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && self::amount($paymentPlanRestrictedCreditAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Store Credit')
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($paymentPlanCreditAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && self::amount($paymentPlanCreditAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make(PaymentPlanFee::LABEL)
                ->color('neutral')
                ->columnSpanFull(),
            Text::make(fn (): string => format_money(self::amount($paymentPlanFeeAmount)))
                ->color('neutral')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && self::amount($paymentPlanFeeAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Pay Today Items')
                ->color('neutral')
                ->columnSpanFull(),
            Text::make(fn (): string => format_money(self::nullableAmount($payTodayItemsAmount) ?? 0))
                ->color('neutral')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && (self::nullableAmount($payTodayItemsAmount) ?? 0) > 0);

        $totalComponents[] = Flex::make([
            Text::make(function () use ($discountLabel): string {
                $label = value($discountLabel);

                return is_string($label) && $label !== '' ? $label : 'Discount';
            })
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($payTodayDiscountAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && self::amount($payTodayDiscountAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Limited Use Credit')
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($payTodayRestrictedCreditAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && self::amount($payTodayRestrictedCreditAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Store Credit')
                ->color('danger')
                ->columnSpanFull(),
            Text::make(fn (): string => '-'.format_money(self::amount($payTodayCreditAmount)))
                ->color('danger')
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null && self::amount($payTodayCreditAmount) > 0);

        $totalComponents[] = Flex::make([
            Text::make('Total')
                ->size('md')
                ->weight(FontWeight::Bold)
                ->columnSpanFull(),
            Text::make(fn (): string => format_money(self::amount($total)))
                ->size('md')
                ->weight(FontWeight::Bold)
                ->grow(false),
        ])
            ->extraAttributes(['class' => 'border-t border-gray-300 pt-2']);

        $totalComponents[] = Text::make(function () use ($template, $total, $paymentPlanTotal): string {
            $paymentPlanTemplate = self::template($template);

            if ($paymentPlanTemplate === null) {
                return '';
            }

            $amounts = $paymentPlanTemplate->installmentAmounts(
                self::nullableAmount($paymentPlanTotal) ?? self::amount($total),
            );

            return "{$paymentPlanTemplate->number_of_installments} payments of ".format_money($amounts['remaining']);
        })
            ->color('neutral')
            ->extraAttributes(['class' => 'border-t border-gray-300 pt-2 w-full'])
            ->visible(fn (): bool => self::template($template) !== null);

        $totalComponents[] = Flex::make([
            Text::make('Amount Due Today')
                ->weight(FontWeight::Bold)
                ->columnSpanFull(),
            Text::make(function () use ($template, $total, $paymentPlanTotal, $amountDueToday): string {
                $paymentPlanTemplate = self::template($template);

                if ($paymentPlanTemplate === null) {
                    return format_money(0);
                }

                $amounts = $paymentPlanTemplate->installmentAmounts(
                    self::nullableAmount($paymentPlanTotal) ?? self::amount($total),
                );
                $dueToday = self::nullableAmount($amountDueToday) ?? $amounts['first'];

                return format_money($dueToday);
            })
                ->weight(FontWeight::Bold)
                ->grow(false),
        ])
            ->visible(fn (): bool => self::template($template) !== null);

        return [
            Grid::make(1)
                ->schema($totalComponents)
                ->gap(false),
        ];
    }

    private static function amount(int|Closure $amount): int
    {
        return (int) value($amount);
    }

    private static function nullableAmount(int|Closure|null $amount): ?int
    {
        $amount = value($amount);

        return is_int($amount) ? $amount : null;
    }

    private static function template(PaymentPlanTemplate|Closure|null $template): ?PaymentPlanTemplate
    {
        $template = value($template);

        return $template instanceof PaymentPlanTemplate ? $template : null;
    }
}
