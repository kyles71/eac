<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Schemas;

use App\Enums\OrderItemStatus;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\OrderItem;
use App\Models\OrderItemFulfillment;
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
                            ->columns(6)
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
                                        OrderItemStatus::PartiallyFulfilled => 'info',
                                        OrderItemStatus::Pending => 'warning',
                                    }),
                                TextEntry::make('fulfillment_workflow')
                                    ->label('Workflow')
                                    ->badge(),
                                TextEntry::make('fulfillment_progress')
                                    ->label('Progress')
                                    ->state(fn (OrderItem $record): string => $record->fulfilledQuantity().' of '.$record->quantity),
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
                                RepeatableEntry::make('fulfillments')
                                    ->label('Fulfillment History')
                                    ->columns(5)
                                    ->schema([
                                        TextEntry::make('unit_number')
                                            ->label('Unit'),
                                        TextEntry::make('source_summary')
                                            ->label('Fulfilled By')
                                            ->state(fn (OrderItemFulfillment $record): string => $record->sourceLabel())
                                            ->url(fn (OrderItemFulfillment $record): ?string => $record->source instanceof Event
                                                ? EventResource::getUrl('view', ['record' => $record->source])
                                                : null),
                                        TextEntry::make('fulfilledBy.full_name')
                                            ->label('Recorded By')
                                            ->placeholder('System'),
                                        TextEntry::make('fulfilled_at')
                                            ->label('Recorded At')
                                            ->dateTime(),
                                        TextEntry::make('fulfillment_status')
                                            ->label('Status')
                                            ->state(fn (OrderItemFulfillment $record): string => $record->isActive() ? 'Active' : 'Reopened')
                                            ->badge()
                                            ->color(fn (OrderItemFulfillment $record): string => $record->isActive() ? 'success' : 'gray'),
                                        TextEntry::make('note')
                                            ->label('Note')
                                            ->placeholder('None')
                                            ->columnSpan(2),
                                        TextEntry::make('void_reason')
                                            ->label('Reopened Reason')
                                            ->placeholder('N/A')
                                            ->columnSpan(2),
                                        TextEntry::make('voided_at')
                                            ->label('Reopened At')
                                            ->dateTime()
                                            ->placeholder('N/A'),
                                    ])
                                    ->columnSpanFull()
                                    ->visible(fn (OrderItem $record): bool => $record->fulfillments->isNotEmpty()),
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
