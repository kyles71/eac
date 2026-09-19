<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\CancelInstallmentPaymentAttempt;
use App\Actions\Store\CreateInstallmentPaymentAttempt;
use App\Actions\Store\FinalizeInstallmentPaymentAttempt;
use App\Actions\Store\ProcessInstallmentPaymentAttempt;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Enums\OrderStatus;
use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\InstallmentPaymentAttemptAllocation;
use App\Models\PaymentPlan;
use App\Models\User;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

final class PayPaymentPlan extends Page
{
    public PaymentPlan $paymentPlan;

    public ?InstallmentPaymentAttempt $paymentAttempt = null;

    public ?InstallmentPaymentAttempt $completedPaymentAttempt = null;

    public ?string $clientSecret = null;

    public ?string $customerSessionClientSecret = null;

    protected static ?string $title = 'Pay Missed Installments';

    protected static ?string $slug = 'payment-plans/{paymentPlan}/pay';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(PaymentPlan $paymentPlan): void
    {
        $paymentPlan->loadMissing(['order.user', 'installments']);

        if ($paymentPlan->order?->user_id !== auth()->id()) {
            throw new AuthorizationException('You cannot pay this payment plan.');
        }

        $this->paymentPlan = $paymentPlan;

        $redirectedPaymentIntentId = request()->query('payment_intent');

        $redirectedPaymentAttempt = is_string($redirectedPaymentIntentId) && $redirectedPaymentIntentId !== ''
            ? $this->finalizeRedirectedPayment($redirectedPaymentIntentId)
            : null;

        $this->loadPaymentState();
        $this->loadCompletedPaymentConfirmation($redirectedPaymentAttempt?->id);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make("Payment plan for Order #{$this->paymentPlan->order_id}")
                ->description('Click the Pay Now button to choose one or more missed installments to pay them together in one secure payment.')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            TextEntry::make('missed_balance')
                                ->label('Missed balance')
                                ->state(fn (): string => format_money((int) $this->missedInstallments()->sum('amount'))),
                            TextEntry::make('remaining_balance')
                                ->label('Plan balance')
                                ->state(fn (): string => format_money($this->paymentPlan->remainingBalance())),
                            TextEntry::make('missed_count')
                                ->label('Missed installments')
                                ->state(fn (): int => $this->missedInstallments()->count()),
                        ]),
                    Actions::make([
                        $this->preparePaymentAction(),
                    ])
                        ->visible(fn (): bool => $this->canPreparePayment()),
                    TextEntry::make('nothing_due')
                        ->hiddenLabel()
                        ->state('There are no failed or overdue installments to pay.')
                        ->visible(fn (): bool => $this->paymentAttempt === null
                            && $this->missedInstallments()->isEmpty()
                            && ! in_array($this->paymentPlan->order?->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)),
                    TextEntry::make('payment_in_progress')
                        ->hiddenLabel()
                        ->state('A payment is already in progress for the missed installments. No additional payment is needed right now.')
                        ->visible(fn (): bool => $this->paymentAttempt === null
                            && $this->missedInstallments()->isNotEmpty()
                            && $this->eligibleInstallments()->isEmpty()
                            && ! in_array($this->paymentPlan->order?->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)),
                    TextEntry::make('plan_not_collectible')
                        ->hiddenLabel()
                        ->state('This payment plan is no longer collectible. Contact us if you need help with this order.')
                        ->visible(fn (): bool => $this->paymentAttempt === null
                            && in_array($this->paymentPlan->order?->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)),
                ]),
            Section::make('Payment successful')
                ->description(fn (): string => format_money($this->completedPaymentAttempt->total_amount ?? 0).' was applied to the selected installments.')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->iconColor('success')
                ->schema([
                    Actions::make([
                        Action::make('payAnother')
                            ->label('Pay another missed installment')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->visible(fn (): bool => $this->eligibleInstallments()->isNotEmpty())
                            ->action(fn () => $this->payAnotherMissedInstallment()),
                        Action::make('returnToBilling')
                            ->label('Return to Billing')
                            ->url(Billing::getUrl()),
                    ]),
                ])
                ->visible(fn (): bool => $this->completedPaymentAttempt !== null),
            Section::make('Review and pay')
                ->description('Confirm the selected installments and amount, then choose a payment method.')
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TextEntry::make('selected_installments')
                                ->label('Selected installments')
                                ->state(fn (): string => $this->selectedInstallmentsSummary()),
                            TextEntry::make('amount_due_now')
                                ->label('Amount due now')
                                ->state(fn (): string => format_money($this->paymentAttempt->total_amount ?? 0)),
                        ]),
                    TextEntry::make('payment_message')
                        ->hiddenLabel()
                        ->state(fn (): ?string => $this->paymentAttempt?->failure_reason)
                        ->visible(fn (): bool => filled($this->paymentAttempt?->failure_reason)),
                    View::make('filament.user.pages.pay-payment-plan-payment')
                        ->visible(fn (): bool => $this->clientSecret !== null
                            && in_array($this->paymentAttempt?->status, [
                                InstallmentPaymentAttemptStatus::Pending,
                                InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
                                InstallmentPaymentAttemptStatus::RequiresAction,
                            ], true)),
                    TextEntry::make('payment_setup_interrupted')
                        ->hiddenLabel()
                        ->state('Payment setup was interrupted before the secure payment form was ready. Retry setup to continue with the same payment attempt.')
                        ->visible(fn (): bool => $this->canResumePaymentSetup()),
                    TextEntry::make('processing_message')
                        ->hiddenLabel()
                        ->state('Stripe is processing this payment. The status will update automatically; no additional payment is needed.')
                        ->visible(fn (): bool => $this->paymentAttempt?->status === InstallmentPaymentAttemptStatus::Processing),
                    Actions::make([
                        Action::make('resumePaymentSetup')
                            ->label('Retry payment setup')
                            ->icon(Heroicon::OutlinedArrowPath)
                            ->visible(fn (): bool => $this->canResumePaymentSetup())
                            ->action(fn () => $this->resumePaymentSetup()),
                        Action::make('cancelPayment')
                            ->label('Cancel and change selection')
                            ->color('gray')
                            ->visible(fn (): bool => $this->paymentAttempt?->status !== InstallmentPaymentAttemptStatus::Processing)
                            ->requiresConfirmation()
                            ->action(fn () => $this->cancelPayment()),
                    ]),
                ])
                ->extraAttributes(fn (): array => $this->paymentAttempt?->status === InstallmentPaymentAttemptStatus::Processing
                    ? ['wire:poll.5s' => 'refreshPaymentStatus']
                    : [])
                ->visible(fn (): bool => $this->paymentAttempt?->status->isActive() ?? false),
            Section::make('Payment was not completed')
                ->description(fn (): string => $this->paymentAttempt->failure_reason
                    ?? 'Please start a new payment and choose another payment method.')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->iconColor('danger')
                ->schema([
                    Actions::make([
                        Action::make('startAgain')
                            ->label('Try Again')
                            ->visible(fn (): bool => $this->paymentAttempt?->status === InstallmentPaymentAttemptStatus::Failed
                                || ($this->paymentAttempt?->status === InstallmentPaymentAttemptStatus::Error
                                    && $this->paymentAttempt->stripe_payment_intent_id === null))
                            ->action(function (): void {
                                $this->paymentAttempt = null;
                                $this->clientSecret = null;
                                $this->customerSessionClientSecret = null;
                            }),
                    ]),
                ])
                ->visible(fn (): bool => in_array($this->paymentAttempt?->status, [
                    InstallmentPaymentAttemptStatus::Failed,
                    InstallmentPaymentAttemptStatus::Error,
                ], true)),
        ]);
    }

    /** @return array{status: string, message: string|null} */
    public function completePayment(string $paymentIntentId): array
    {
        if ($this->paymentAttempt === null || ! $this->paymentAttempt->status->isActive()) {
            return ['status' => 'invalid', 'message' => 'This payment attempt is no longer active.'];
        }

        try {
            $paymentIntent = $this->stripeService()->retrievePaymentIntent($paymentIntentId);
            $this->paymentAttempt = app(FinalizeInstallmentPaymentAttempt::class)->handle(
                $this->paymentAttempt,
                $paymentIntent,
            );
            $this->paymentPlan->refresh()->load('installments');

            if ($this->paymentAttempt->status === InstallmentPaymentAttemptStatus::Succeeded) {
                $this->queueCompletedPaymentConfirmation($this->paymentAttempt);
            }

            return [
                'status' => $this->paymentAttempt->status->value,
                'message' => $this->paymentAttempt->failure_reason,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'error', 'message' => 'We could not confirm the payment status. Please refresh this page.'];
        }
    }

    /** @return array{successful: bool, message: string|null} */
    public function configureFuturePayments(bool $useForFuture): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->paymentAttempt === null) {
            return ['successful' => false, 'message' => 'This payment attempt is no longer active.'];
        }

        $paymentAttempt = InstallmentPaymentAttempt::query()
            ->whereKey($this->paymentAttempt->id)
            ->where('payment_plan_id', $this->paymentPlan->id)
            ->where('origin', InstallmentPaymentAttemptOrigin::Customer)
            ->whereIn('status', [
                InstallmentPaymentAttemptStatus::Pending,
                InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
            ])
            ->whereHas('paymentPlan.order', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if ($paymentAttempt?->stripe_payment_intent_id === null) {
            return ['successful' => false, 'message' => 'This payment attempt is no longer ready for payment.'];
        }

        try {
            $this->stripeService()->updatePaymentIntentSetupFutureUsage(
                $paymentAttempt->stripe_payment_intent_id,
                $useForFuture,
            );

            $paymentAttempt->update(['use_for_future' => $useForFuture]);
            $this->paymentAttempt = $paymentAttempt->refresh();

            return ['successful' => true, 'message' => null];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'successful' => false,
                'message' => 'We could not update the future payment preference. Please try again.',
            ];
        }
    }

    public function refreshPaymentStatus(): void
    {
        if ($this->paymentAttempt?->status !== InstallmentPaymentAttemptStatus::Processing
            || $this->paymentAttempt->stripe_payment_intent_id === null) {
            return;
        }

        try {
            $paymentAttempt = app(FinalizeInstallmentPaymentAttempt::class)->handle(
                $this->paymentAttempt,
                $this->stripeService()->retrievePaymentIntent($this->paymentAttempt->stripe_payment_intent_id),
            );
            $this->paymentPlan->refresh()->load('installments');

            if ($paymentAttempt->status === InstallmentPaymentAttemptStatus::Succeeded) {
                $this->loadCompletedPaymentConfirmation($paymentAttempt->id);
            }

            $this->loadPaymentState();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function preparePaymentAction(): Action
    {
        return Action::make('preparePayment')
            ->label('Pay Now')
            ->icon(Heroicon::OutlinedCreditCard)
            ->fillForm(fn (): array => [
                'installment_ids' => $this->eligibleInstallments()->take(1)->pluck('id')->all(),
            ])
            ->schema([
                CheckboxList::make('installment_ids')
                    ->label('Missed installments')
                    ->options(fn (): array => $this->eligibleInstallments()
                        ->mapWithKeys(fn (Installment $installment): array => [
                            $installment->id => "#{$installment->installment_number} — ".format_money($installment->amount)." (due {$installment->due_date->format('M j, Y')})",
                        ])
                        ->all())
                    ->required(),
            ])
            ->modalSubmitActionLabel('Continue to Payment')
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->action(function (Action $action, array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    $action->halt();

                    return;
                }

                try {
                    $paymentAttempt = app(CreateInstallmentPaymentAttempt::class)->handle(
                        paymentPlan: $this->paymentPlan,
                        installmentIds: $data['installment_ids'] ?? [],
                        origin: InstallmentPaymentAttemptOrigin::Customer,
                        initiatedBy: $user,
                        useForFuture: false,
                    );

                    $this->paymentAttempt = app(ProcessInstallmentPaymentAttempt::class)->handle($paymentAttempt);
                    $this->setPaymentSecrets();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not start payment')
                        ->body(self::customerSafeExceptionMessage(
                            $exception,
                            'Stripe could not prepare the secure payment form. Please try again shortly.',
                        ))
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    public function payAnotherMissedInstallment(): void
    {
        $this->completedPaymentAttempt = null;
        $this->mountAction('preparePayment');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToBilling')
                ->label('Back to Billing')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Billing::getUrl()),
        ];
    }

    private static function customerSafeExceptionMessage(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof AuthorizationException
            || $exception instanceof DomainException
            || $exception instanceof InvalidArgumentException) {
            return $exception->getMessage();
        }

        return $fallback;
    }

    private function cancelPayment(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->paymentAttempt === null) {
            return;
        }

        try {
            $paymentAttempt = app(CancelInstallmentPaymentAttempt::class)->handle($this->paymentAttempt, $user);

            if ($paymentAttempt->status->isActive()) {
                $this->paymentAttempt = $paymentAttempt;

                Notification::make()
                    ->title('Payment is already processing')
                    ->body('This payment can no longer be cancelled. Its status will update automatically.')
                    ->warning()
                    ->send();
            } else {
                $this->paymentAttempt = null;
                $this->clientSecret = null;
                $this->customerSessionClientSecret = null;
            }
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not cancel payment')
                ->body(self::customerSafeExceptionMessage(
                    $exception,
                    'Stripe could not cancel this payment. Please try again shortly.',
                ))
                ->danger()
                ->send();
        }
    }

    private function finalizeRedirectedPayment(string $paymentIntentId): ?InstallmentPaymentAttempt
    {
        $paymentAttempt = InstallmentPaymentAttempt::query()
            ->where('payment_plan_id', $this->paymentPlan->id)
            ->where('origin', InstallmentPaymentAttemptOrigin::Customer)
            ->where('initiated_by_user_id', auth()->id())
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->whereHas('paymentPlan.order', fn ($query) => $query->where('user_id', auth()->id()))
            ->first();

        if ($paymentAttempt === null) {
            return null;
        }

        try {
            return app(FinalizeInstallmentPaymentAttempt::class)->handle(
                $paymentAttempt,
                $this->stripeService()->retrievePaymentIntent($paymentIntentId),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function loadPaymentState(): void
    {
        $paymentAttempt = InstallmentPaymentAttempt::query()
            ->where('payment_plan_id', $this->paymentPlan->id)
            ->where('origin', InstallmentPaymentAttemptOrigin::Customer)
            ->where('initiated_by_user_id', auth()->id())
            ->active()
            ->with('allocations.installment')
            ->latest('id')
            ->first();

        $this->paymentAttempt = $paymentAttempt;

        if ($this->paymentAttempt === null) {
            $this->clientSecret = null;
            $this->customerSessionClientSecret = null;

            return;
        }

        if ($this->paymentAttempt->status->isActive()) {
            try {
                $this->setPaymentSecrets();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function resumePaymentSetup(): void
    {
        if (! $this->canResumePaymentSetup() || $this->paymentAttempt === null) {
            return;
        }

        try {
            $this->paymentAttempt = app(ProcessInstallmentPaymentAttempt::class)->handle($this->paymentAttempt);
            $this->setPaymentSecrets();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Could not resume payment setup')
                ->body('Stripe could not prepare the secure payment form. Please try again shortly.')
                ->danger()
                ->send();
        }
    }

    private function canResumePaymentSetup(): bool
    {
        return $this->paymentAttempt?->status->isActive() === true
            && $this->paymentAttempt->status !== InstallmentPaymentAttemptStatus::Processing
            && $this->clientSecret === null;
    }

    private function canPreparePayment(): bool
    {
        if ($this->paymentAttempt !== null
            && $this->paymentAttempt->status !== InstallmentPaymentAttemptStatus::Succeeded) {
            return false;
        }

        return $this->eligibleInstallments()->isNotEmpty()
            && ! in_array($this->paymentPlan->order?->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true);
    }

    private function loadCompletedPaymentConfirmation(?int $paymentAttemptId = null): void
    {
        $pendingSessionKey = $this->paymentConfirmationSessionKey('pending');

        if ($paymentAttemptId === null) {
            $pendingPaymentAttemptId = session()->pull($pendingSessionKey);
            $paymentAttemptId = is_numeric($pendingPaymentAttemptId)
                ? (int) $pendingPaymentAttemptId
                : null;
        } else {
            session()->forget($pendingSessionKey);
        }

        if ($paymentAttemptId === null
            || (int) session()->get($this->paymentConfirmationSessionKey('shown')) === $paymentAttemptId) {
            return;
        }

        $paymentAttempt = InstallmentPaymentAttempt::query()
            ->whereKey($paymentAttemptId)
            ->where('payment_plan_id', $this->paymentPlan->id)
            ->where('origin', InstallmentPaymentAttemptOrigin::Customer)
            ->where('initiated_by_user_id', auth()->id())
            ->where('status', InstallmentPaymentAttemptStatus::Succeeded)
            ->with('allocations.installment')
            ->first();

        if ($paymentAttempt === null) {
            return;
        }

        $this->completedPaymentAttempt = $paymentAttempt;
        session()->put($this->paymentConfirmationSessionKey('shown'), $paymentAttempt->id);
    }

    private function queueCompletedPaymentConfirmation(InstallmentPaymentAttempt $paymentAttempt): void
    {
        if ($paymentAttempt->payment_plan_id !== $this->paymentPlan->id
            || $paymentAttempt->initiated_by_user_id !== auth()->id()
            || $paymentAttempt->origin !== InstallmentPaymentAttemptOrigin::Customer
            || $paymentAttempt->status !== InstallmentPaymentAttemptStatus::Succeeded) {
            return;
        }

        session()->put($this->paymentConfirmationSessionKey('pending'), $paymentAttempt->id);
    }

    private function paymentConfirmationSessionKey(string $state): string
    {
        return 'payment-plan-payments.'.auth()->id().".{$this->paymentPlan->id}.confirmation.{$state}";
    }

    private function selectedInstallmentsSummary(): string
    {
        if ($this->paymentAttempt === null) {
            return '—';
        }

        $this->paymentAttempt->loadMissing('allocations.installment');

        return $this->paymentAttempt->allocations
            ->sortBy(fn (InstallmentPaymentAttemptAllocation $allocation): int => $allocation->installment->installment_number)
            ->map(fn (InstallmentPaymentAttemptAllocation $allocation): string => '#'.$allocation->installment->installment_number
                .' — due '.$allocation->installment->due_date->format('M j, Y'))
            ->join('; ');
    }

    private function setPaymentSecrets(): void
    {
        if ($this->paymentAttempt === null || ! $this->paymentAttempt->status->isActive()) {
            return;
        }

        if ($this->paymentAttempt->stripe_payment_intent_id === null) {
            return;
        }

        $paymentIntent = $this->stripeService()->retrievePaymentIntent($this->paymentAttempt->stripe_payment_intent_id);
        $this->clientSecret = $paymentIntent->client_secret;

        if ($this->paymentAttempt->stripe_customer_id !== null) {
            $customerSession = $this->stripeService()->createCustomerSession(
                $this->paymentAttempt->stripe_customer_id,
                allowPaymentMethodSave: false,
            );
            $this->customerSessionClientSecret = $customerSession->client_secret;
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Installment> */
    private function eligibleInstallments(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->paymentPlan->installments()
            ->collectibleMissed()
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Installment> */
    private function missedInstallments(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->paymentPlan->installments()
            ->missed()
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();
    }

    private function stripeService(): StripeServiceContract
    {
        return app(StripeServiceContract::class);
    }
}
