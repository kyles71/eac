<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Pages;

use App\Actions\Store\MarkInstallmentsPaid;
use App\Filament\Actions\AdjustPaymentPlanDueDatesAction;
use App\Filament\Actions\RetryPaymentPlanAction;
use App\Filament\Actions\SendPaymentPlanPayNowLinkAction;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Models\Installment;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class ViewPaymentPlan extends ViewRecord
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        /** @var \App\Models\PaymentPlan $record */
        $record = $this->getRecord();

        return [
            RetryPaymentPlanAction::make(),
            SendPaymentPlanPayNowLinkAction::make(),
            AdjustPaymentPlanDueDatesAction::make(),
            Action::make('markInstallmentPaid')
                ->label('Mark Installment Paid')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => $record->hasReschedulableInstallments()
                    && $record->installments()
                        ->reschedulable()
                        ->withoutActivePaymentAttempt()
                        ->exists())
                ->form([
                    CheckboxList::make('installment_ids')
                        ->label('Select installments to mark as paid')
                        ->options(function () use ($record): array {
                            /** @var \Illuminate\Database\Eloquent\Collection<int, Installment> $installments */
                            $installments = $record->installments()
                                ->reschedulable()
                                ->notBlockedByRefundCancellation()
                                ->withoutActivePaymentAttempt()
                                ->get();

                            return $installments
                                ->mapWithKeys(fn (Installment $installment): array => [
                                    $installment->id => "#{$installment->installment_number} — ".format_money($installment->amount)." (due {$installment->due_date->format('M j, Y')})",
                                ])
                                ->all();
                        })
                        ->required(),
                ])
                ->action(function (Action $action, array $data) use ($record): void {
                    try {
                        $markedPaid = app(MarkInstallmentsPaid::class)->handle(
                            $record,
                            $data['installment_ids'] ?? [],
                        );

                        Notification::make()
                            ->title('Installments marked as paid')
                            ->body($markedPaid.' installment(s) marked as paid.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Could not mark installments as paid')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}
