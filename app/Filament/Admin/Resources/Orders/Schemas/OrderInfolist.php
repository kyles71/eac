<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
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
                                OrderStatus::Failed => 'danger',
                                OrderStatus::Refunded => 'gray',
                            }),
                        TextEntry::make('subtotal')
                            ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                        TextEntry::make('total')
                            ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                        TextEntry::make('stripe_checkout_session_id')
                            ->label('Stripe Session ID')
                            ->placeholder('N/A')
                            ->copyable(),
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
                            ->schema([
                                TextEntry::make('product.name')
                                    ->label('Product'),
                                TextEntry::make('quantity'),
                                TextEntry::make('unit_price')
                                    ->label('Unit Price')
                                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                                TextEntry::make('total_price')
                                    ->label('Total')
                                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                            ]),
                    ]),
            ]);
    }
}
