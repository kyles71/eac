<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Schemas;

use App\Enums\OrderItemStatus;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\OrderRefund;
use App\Models\ProductQuestionAnswer;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order Information')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id')
                            ->label('Order #'),
                        TextEntry::make('user.full_name')
                            ->label('Customer'),
                        TextEntry::make('user.email')
                            ->label('Email'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (OrderStatus $state): string => match ($state) {
                                OrderStatus::Completed => 'success',
                                OrderStatus::Pending => 'warning',
                                OrderStatus::Processing => 'info',
                                OrderStatus::PartiallyRefunded => 'warning',
                                OrderStatus::Failed => 'danger',
                                OrderStatus::Refunded => 'gray',
                                OrderStatus::Cancelled => 'gray',
                            }),
                        TextEntry::make('subtotal')
                            ->moneyCents(),
                        TextEntry::make('payment_plan_fee')
                            ->label('Payment Plan Fee')
                            ->moneyCents(),
                        TextEntry::make('total')
                            ->moneyCents(),
                        TextEntry::make('stripe_payment_intent_id')
                            ->label('Stripe Payment Intent')
                            ->placeholder('N/A')
                            ->copyable(),
                        TextEntry::make('created_at')
                            ->label('Ordered At')
                            ->dateTime(),
                    ]),
                Section::make('Order Items')
                    ->schema([
                        RepeatableEntry::make('orderItems')
                            ->hiddenLabel()
                            ->columns(5)
                            ->schema([
                                TextEntry::make('product.name')
                                    ->label('Product'),
                                TextEntry::make('quantity'),
                                TextEntry::make('unit_price')
                                    ->label('Unit Price')
                                    ->moneyCents(),
                                TextEntry::make('total_price')
                                    ->label('Total')
                                    ->moneyCents(),
                                TextEntry::make('status')
                                    ->label('Fulfillment')
                                    ->badge()
                                    ->color(fn (OrderItemStatus $state): string => match ($state) {
                                        OrderItemStatus::Fulfilled => 'success',
                                        OrderItemStatus::Pending => 'warning',
                                    }),
                                RepeatableEntry::make('questionAnswers')
                                    ->label('Purchaser Answers')
                                    ->schema([
                                        TextEntry::make('unit_number')
                                            ->label('Item'),
                                        TextEntry::make('question'),
                                        TextEntry::make('display_answer')
                                            ->label('Answer')
                                            ->state(fn (ProductQuestionAnswer $record): string => $record->formattedAnswer()),
                                    ])
                                    ->columns(3)
                                    ->columnSpanFull()
                                    ->visible(fn ($record): bool => $record->questionAnswers->isNotEmpty()),
                            ]),
                    ]),
                Section::make('Refund History')
                    ->schema([
                        RepeatableEntry::make('refunds')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('id')
                                    ->label('Refund #'),
                                TextEntry::make('amount')
                                    ->moneyCents(),
                                TextEntry::make('processedBy.full_name')
                                    ->label('Processed By')
                                    ->placeholder('System / Stripe Dashboard'),
                                TextEntry::make('status')
                                    ->badge(),
                                TextEntry::make('reason')
                                    ->label('Internal Reason')
                                    ->columnSpanFull(),
                                TextEntry::make('effects')
                                    ->label('Additional Actions')
                                    ->state(fn (OrderRefund $record): array => $record->additionalActionDescriptions())
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->placeholder('None')
                                    ->columnSpan(2),
                                TextEntry::make('created_at')
                                    ->label('Requested At')
                                    ->dateTime(),
                                TextEntry::make('completed_at')
                                    ->label('Completed At')
                                    ->dateTime()
                                    ->placeholder('Pending'),
                                RepeatableEntry::make('payments')
                                    ->label('Stripe Refunds')
                                    ->columns(4)
                                    ->schema([
                                        TextEntry::make('stripe_payment_intent_id')
                                            ->label('Payment Intent')
                                            ->copyable(),
                                        TextEntry::make('stripe_refund_id')
                                            ->label('Refund ID')
                                            ->copyable()
                                            ->placeholder('Pending'),
                                        TextEntry::make('amount')
                                            ->moneyCents(),
                                        TextEntry::make('status')
                                            ->badge()
                                            ->color(fn (OrderRefundPaymentStatus $state): string => match ($state) {
                                                OrderRefundPaymentStatus::Succeeded => 'success',
                                                OrderRefundPaymentStatus::Failed, OrderRefundPaymentStatus::Canceled => 'danger',
                                                default => 'warning',
                                            }),
                                        TextEntry::make('failure_reason')
                                            ->label('Failure')
                                            ->visible(fn ($record): bool => filled($record->failure_reason))
                                            ->columnSpanFull(),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull()
                    ->visible(fn ($record): bool => $record->refunds->isNotEmpty()),
            ]);
    }
}
