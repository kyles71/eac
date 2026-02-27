<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Schemas;

use App\Enums\InstallmentStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PaymentPlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment Plan Details')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Plan #'),
                        TextEntry::make('order.id')
                            ->label('Order #')
                            ->formatStateUsing(fn (int $state): string => "#{$state}"),
                        TextEntry::make('order.user.full_name')
                            ->label('Customer'),
                        TextEntry::make('template.name')
                            ->label('Template')
                            ->placeholder('Deleted template'),
                        TextEntry::make('method')
                            ->badge(),
                        TextEntry::make('frequency')
                            ->badge(),
                        TextEntry::make('total_amount')
                            ->label('Total')
                            ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                        TextEntry::make('number_of_installments')
                            ->label('Installments'),
                        TextEntry::make('stripe_customer_id')
                            ->label('Stripe Customer')
                            ->placeholder('N/A')
                            ->copyable(),
                        TextEntry::make('stripe_payment_method_id')
                            ->label('Stripe Payment Method')
                            ->placeholder('N/A')
                            ->copyable(),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                    ]),
                Section::make('Installments')
                    ->schema([
                        RepeatableEntry::make('installments')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('installment_number')
                                    ->label('#'),
                                TextEntry::make('amount')
                                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2)),
                                TextEntry::make('due_date')
                                    ->label('Due Date')
                                    ->date(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (InstallmentStatus $state): string => match ($state) {
                                        InstallmentStatus::Paid => 'success',
                                        InstallmentStatus::Pending => 'warning',
                                        InstallmentStatus::Failed => 'danger',
                                        InstallmentStatus::Overdue => 'danger',
                                    }),
                                TextEntry::make('paid_at')
                                    ->label('Paid At')
                                    ->dateTime()
                                    ->placeholder('—'),
                                TextEntry::make('retry_count')
                                    ->label('Retries'),
                            ]),
                    ]),
            ]);
    }
}
