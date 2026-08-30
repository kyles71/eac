<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Schemas;

use App\Models\Installment;
use App\Models\InstallmentDueDateAdjustment;
use App\Models\PaymentPlan;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
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
                    ->columns(3)
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
                        TextEntry::make('frequency')
                            ->badge(),
                        TextEntry::make('total_amount')
                            ->label('Total')
                            ->moneyCents(),
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
                                    ->moneyCents(),
                                TextEntry::make('due_date')
                                    ->label('Due Date')
                                    ->date(),
                                TextEntry::make('status')
                                    ->badge()
                                    ->state(fn (Installment $record): string => $record->paymentStatusLabel())
                                    ->color(fn (Installment $record): string => $record->paymentStatusColor()),
                                TextEntry::make('retry_count')
                                    ->label('Retries'),
                                TextEntry::make('paid_at')
                                    ->label('Paid At')
                                    ->dateTime()
                                    ->placeholder('—'),
                            ])
                            ->columns(3),
                    ]),
                Section::make('Due Date Adjustment History')
                    ->columnSpanFull()
                    ->extraAttributes(['style' => 'min-width: 0;'])
                    ->schema([
                        RepeatableEntry::make('dueDateAdjustments')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Installment'),
                                TableColumn::make('Previous Status')->wrapHeader(),
                                TableColumn::make('Previous Due Date')->wrapHeader(),
                                TableColumn::make('New Due Date')->wrapHeader(),
                                TableColumn::make('Reason')->wrapHeader(),
                                TableColumn::make('Customer Email')->wrapHeader(),
                                TableColumn::make('Adjusted By')->wrapHeader(),
                                TableColumn::make('Adjusted At')->wrapHeader(),
                            ])
                            ->schema([
                                TextEntry::make('installment.installment_number')
                                    ->label('Installment')
                                    ->formatStateUsing(fn (int $state): string => "#{$state}"),
                                TextEntry::make('previous_status')
                                    ->label('Previous Status')
                                    ->badge(),
                                TextEntry::make('old_due_date')
                                    ->label('Previous Due Date')
                                    ->date(),
                                TextEntry::make('new_due_date')
                                    ->label('New Due Date')
                                    ->date(),
                                TextEntry::make('reason')
                                    ->label('Reason'),
                                TextEntry::make('customer_notification_status')
                                    ->label('Customer Email')
                                    ->badge()
                                    ->tooltip(fn (InstallmentDueDateAdjustment $record): ?string => $record->customer_notification_note)
                                    ->color(fn (string $state): string => match ($state) {
                                        'Queued' => 'success',
                                        'Skipped' => 'warning',
                                        'Failed' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('adjustedBy.full_name')
                                    ->label('Adjusted By')
                                    ->placeholder('Deleted administrator'),
                                TextEntry::make('created_at')
                                    ->label('Adjusted At')
                                    ->dateTime(),
                            ])
                            ->extraAttributes([
                                'style' => 'min-width: 0; max-width: 100%; overflow-x: auto;',
                            ]),
                    ])
                    ->visible(fn (?PaymentPlan $record): bool => $record?->dueDateAdjustments()->exists() ?? false),
            ]);
    }
}
