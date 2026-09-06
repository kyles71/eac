<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Store\CreateInstallmentPaymentAttempt;
use App\Actions\Store\ProcessInstallmentPaymentAttempt;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\PaymentPlan;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class RetryPaymentPlanAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Retry Payment')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->authorize('retryPayment')
            ->visible(fn (?PaymentPlan $record): bool => $record instanceof PaymentPlan
                && self::eligibleInstallments($record)->isNotEmpty())
            ->modalHeading('Retry missed installments')
            ->modalDescription('This immediately charges the payment method currently assigned to the payment plan.')
            ->modalSubmitActionLabel('Charge Payment Method')
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->fillForm(fn (PaymentPlan $record): array => [
                'installment_ids' => self::eligibleInstallments($record)
                    ->take(1)
                    ->pluck('id')
                    ->all(),
            ])
            ->schema([
                CheckboxList::make('installment_ids')
                    ->label('Missed installments')
                    ->options(fn (PaymentPlan $record): array => self::eligibleInstallments($record)
                        ->mapWithKeys(fn (Installment $installment): array => [
                            $installment->id => "#{$installment->installment_number} — ".format_money($installment->amount)." (due {$installment->due_date->format('M j, Y')})",
                        ])
                        ->all())
                    ->required(),
            ])
            ->action(function (Action $action, PaymentPlan $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    $action->halt();

                    return;
                }

                try {
                    if (! is_string($record->stripe_payment_method_id) || $record->stripe_payment_method_id === '') {
                        throw new DomainException('This payment plan has no assigned payment method. Send the customer a Pay Now link instead.');
                    }

                    $doNotRetry = $record->paymentAttempts()
                        ->where('stripe_payment_method_id', $record->stripe_payment_method_id)
                        ->where('advice_code', 'do_not_try_again')
                        ->exists();

                    if ($doNotRetry) {
                        throw new DomainException('Stripe advises against retrying this payment method. Send the customer a Pay Now link instead.');
                    }

                    $paymentAttempt = app(CreateInstallmentPaymentAttempt::class)->handle(
                        paymentPlan: $record,
                        installmentIds: $data['installment_ids'] ?? [],
                        origin: InstallmentPaymentAttemptOrigin::Administrator,
                        initiatedBy: $actor,
                        stripePaymentMethodId: $record->stripe_payment_method_id,
                    );
                    $paymentAttempt = app(ProcessInstallmentPaymentAttempt::class)->handle($paymentAttempt);

                    match ($paymentAttempt->status) {
                        InstallmentPaymentAttemptStatus::Succeeded => Notification::make()
                            ->title('Payment succeeded')
                            ->body(format_money($paymentAttempt->total_amount).' was collected.')
                            ->success()
                            ->send(),
                        InstallmentPaymentAttemptStatus::Processing,
                        InstallmentPaymentAttemptStatus::Pending => Notification::make()
                            ->title('Payment is processing')
                            ->body('Stripe is still processing the charge. Its status will update automatically.')
                            ->info()
                            ->send(),
                        default => Notification::make()
                            ->title('Payment was not completed')
                            ->body(($paymentAttempt->failure_reason ?? 'The customer must complete or update their payment method.').' You can send a Pay Now link.')
                            ->danger()
                            ->send(),
                    };

                    $record->refresh()->load('installments');
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not retry payment')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    public static function getDefaultName(): string
    {
        return 'retryPaymentPlan';
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Installment> */
    private static function eligibleInstallments(PaymentPlan $paymentPlan): \Illuminate\Database\Eloquent\Collection
    {
        return $paymentPlan->installments()
            ->whereIn('status', [InstallmentStatus::Failed, InstallmentStatus::Overdue])
            ->withoutActivePaymentAttempt()
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();
    }
}
