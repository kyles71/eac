<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\SwitchPaymentPlanMethod;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\Installment;
use App\Models\PaymentPlan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

final class MyPaymentPlans extends TablePage
{
    protected static ?string $title = 'My Payment Plans';

    protected static ?string $slug = 'payment-plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $count = PaymentPlan::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', auth()->id()))
            ->whereHas('installments', fn ($q) => $q->where('status', InstallmentStatus::Pending))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(
                PaymentPlan::query()
                    ->whereHas('order', fn ($q) => $q->where('user_id', auth()->id()))
                    ->with(['order', 'template', 'installments'])
            )
            ->columns([
                TextColumn::make('order.id')
                    ->label('Order')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => format_money($state))
                    ->sortable(),
                TextColumn::make('number_of_installments')
                    ->label('Installments')
                    ->sortable(),
                TextColumn::make('frequency')
                    ->label('Frequency')
                    ->badge(),
                TextColumn::make('method')
                    ->label('Method')
                    ->badge(),
                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->state(fn (PaymentPlan $record): string => format_money($record->amountPaid())),
                TextColumn::make('remaining_balance')
                    ->label('Remaining')
                    ->state(fn (PaymentPlan $record): string => format_money($record->remainingBalance())),
                TextColumn::make('next_due')
                    ->label('Next Due')
                    ->state(function (PaymentPlan $record): string {
                        /** @var \App\Models\Installment|null $nextInstallment */
                        $nextInstallment = $record->installments
                            ->where('status', InstallmentStatus::Pending)
                            ->sortBy('due_date')
                            ->first();

                        if ($nextInstallment === null) {
                            return $record->isFullyPaid() ? 'Fully Paid' : 'N/A';
                        }

                        return $nextInstallment->due_date->format('M j, Y');
                    }),
            ])
            ->recordActions([
                Action::make('switchMethod')
                    ->label('Switch Method')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (PaymentPlan $record): bool => ! $record->isFullyPaid())
                    ->schema([
                        Select::make('method')
                            ->label('Payment Method')
                            ->options(PaymentPlanMethod::class)
                            ->required(),
                    ])
                    ->action(function (PaymentPlan $record, array $data): void {
                        try {
                            $switchMethod = new SwitchPaymentPlanMethod;
                            $switchMethod->handle($record, PaymentPlanMethod::from($data['method']));

                            Notification::make()
                                ->title('Payment method updated')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Could not switch method')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('viewInstallments')
                    ->label('View Installments')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (PaymentPlan $record): string => "Installments for Order #{$record->order_id}")
                    ->schema(fn (PaymentPlan $record): array => $record->loadMissing('installments')
                        ->installments
                        ->sortBy('installment_number')
                        ->map(fn (Installment $installment): Section => Section::make("#{$installment->installment_number}")
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        TextEntry::make('amount')
                                            ->label('Amount')
                                            ->state(format_money($installment->amount)),
                                        TextEntry::make('due_date')
                                            ->label('Due Date')
                                            ->state($installment->due_date->format('M j, Y')),
                                        TextEntry::make('status')
                                            ->label('Status')
                                            ->state($installment->status)
                                            ->badge(),
                                        TextEntry::make('paid_at')
                                            ->label('Paid At')
                                            ->state($installment->paid_at?->format('M j, Y') ?? '—'),
                                    ]),
                            ])
                            ->compact()
                        )
                        ->all()
                    )
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No payment plans')
            ->emptyStateDescription('You don\'t have any active payment plans.');
    }
}
