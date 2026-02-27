<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Pages;

use App\Enums\InstallmentStatus;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Models\Installment;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewPaymentPlan extends ViewRecord
{
    protected static string $resource = PaymentPlanResource::class;

    protected function getHeaderActions(): array
    {
        /** @var \App\Models\PaymentPlan $record */
        $record = $this->getRecord();

        return [
            Action::make('markInstallmentPaid')
                ->label('Mark Installment Paid')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => $record->installments()
                    ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::Failed, InstallmentStatus::Overdue])
                    ->exists())
                ->form([
                    CheckboxList::make('installment_ids')
                        ->label('Select installments to mark as paid')
                        ->options(function () use ($record): array {
                            /** @var \Illuminate\Database\Eloquent\Collection<int, Installment> $installments */
                            $installments = $record->installments()
                                ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::Failed, InstallmentStatus::Overdue])
                                ->get();

                            return $installments
                                ->mapWithKeys(fn (Installment $installment): array => [
                                    $installment->id => "#{$installment->installment_number} — \${$this->formatCents($installment->amount)} (due {$installment->due_date->format('M j, Y')})",
                                ])
                                ->all();
                        })
                        ->required(),
                ])
                ->action(function (array $data) use ($record): void {
                    $installments = $record->installments()
                        ->whereIn('id', $data['installment_ids'])
                        ->get();

                    /** @var Installment $installment */
                    foreach ($installments as $installment) {
                        $installment->markPaid();
                    }

                    Notification::make()
                        ->title('Installments marked as paid')
                        ->body($installments->count().' installment(s) marked as paid.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
