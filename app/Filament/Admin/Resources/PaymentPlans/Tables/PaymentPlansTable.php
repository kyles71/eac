<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Tables;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanFrequency;
use App\Models\Installment;
use App\Models\PaymentPlan;
use Carbon\CarbonInterface;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PaymentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['installments', 'order.user']))
            ->columns([
                TextColumn::make('order.user.full_name')
                    ->label('Customer')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('order.id')
                    ->label('Order')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable()
                    ->copyable(),
                TextColumn::make('payment_status')
                    ->label('Status')
                    ->state(fn (PaymentPlan $record): string => self::status($record))
                    ->badge()
                    ->color(fn (PaymentPlan $record): string => self::statusColor(self::status($record)))
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('installment_progress')
                    ->label('Progress')
                    ->state(fn (PaymentPlan $record): string => self::installmentProgress($record))
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('next_due_date')
                    ->label('Next Due')
                    ->state(fn (PaymentPlan $record): ?CarbonInterface => self::nextUnpaidInstallment($record)?->due_date)
                    ->date()
                    ->placeholder('Paid')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('next_payment_amount')
                    ->label('Payment Amount')
                    ->state(fn (PaymentPlan $record): ?int => self::nextUnpaidInstallment($record)?->amount)
                    ->moneyCents('Paid')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('frequency')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->state(fn (PaymentPlan $record): int => self::paidAmount($record))
                    ->moneyCents()
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (PaymentPlan $record): int => self::remainingBalance($record))
                    ->moneyCents()
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('payment_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'paid' => 'Paid',
                        'overdue' => 'Overdue',
                        'failed' => 'Failed',
                        'no_installments' => 'No installments',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyStatusFilter($query, $data['value'] ?? null)),
                SelectFilter::make('frequency')
                    ->options(PaymentPlanFrequency::class),
            ]);
    }

    private static function status(PaymentPlan $record): string
    {
        $installments = self::installments($record);

        if ($installments->isEmpty()) {
            return 'No installments';
        }

        if ($installments->every(fn (Installment $installment): bool => $installment->status === InstallmentStatus::Paid)) {
            return 'Paid';
        }

        if ($installments->contains(fn (Installment $installment): bool => $installment->status === InstallmentStatus::Overdue)) {
            return 'Overdue';
        }

        if ($installments->contains(fn (Installment $installment): bool => $installment->status === InstallmentStatus::Failed)) {
            return 'Failed';
        }

        return 'Active';
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'Paid' => 'success',
            'Overdue', 'Failed' => 'danger',
            'No installments' => 'gray',
            default => 'warning',
        };
    }

    private static function installmentProgress(PaymentPlan $record): string
    {
        $installments = self::installments($record);
        $paid = $installments->filter(fn (Installment $installment): bool => $installment->status === InstallmentStatus::Paid)->count();
        $total = $installments->count();

        return "{$paid} / {$total} paid";
    }

    private static function nextUnpaidInstallment(PaymentPlan $record): ?Installment
    {
        return self::installments($record)
            ->filter(fn (Installment $installment): bool => $installment->status !== InstallmentStatus::Paid)
            ->sortBy('due_date')
            ->first();
    }

    private static function paidAmount(PaymentPlan $record): int
    {
        return (int) self::installments($record)
            ->filter(fn (Installment $installment): bool => $installment->status === InstallmentStatus::Paid)
            ->sum('amount');
    }

    private static function remainingBalance(PaymentPlan $record): int
    {
        return $record->total_amount - self::paidAmount($record);
    }

    private static function applyStatusFilter(Builder $query, mixed $status): Builder
    {
        return match ($status) {
            'active' => $query
                ->whereHas('installments', fn (Builder $query): Builder => $query->where('status', InstallmentStatus::Pending->value))
                ->whereDoesntHave('installments', fn (Builder $query): Builder => $query->whereIn('status', [
                    InstallmentStatus::Failed->value,
                    InstallmentStatus::Overdue->value,
                ])),
            'paid' => $query
                ->whereHas('installments')
                ->whereDoesntHave('installments', fn (Builder $query): Builder => $query->where('status', '!=', InstallmentStatus::Paid->value)),
            'overdue' => $query->whereHas('installments', fn (Builder $query): Builder => $query->where('status', InstallmentStatus::Overdue->value)),
            'failed' => $query->whereHas('installments', fn (Builder $query): Builder => $query->where('status', InstallmentStatus::Failed->value)),
            'no_installments' => $query->doesntHave('installments'),
            default => $query,
        };
    }

    /**
     * @return Collection<int, Installment>
     */
    private static function installments(PaymentPlan $record): Collection
    {
        if ($record->relationLoaded('installments')) {
            return $record->installments;
        }

        return $record->installments()->get();
    }
}
