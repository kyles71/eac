<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Models\InstallmentPaymentAttempt;
use App\Models\User;
use DomainException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Throwable;

final readonly class ProcessInstallmentPaymentAttempt
{
    public function __construct(
        private StripeServiceContract $stripeService,
        private FinalizeInstallmentPaymentAttempt $finalizePaymentAttempt,
    ) {}

    public function handle(InstallmentPaymentAttempt $paymentAttempt): InstallmentPaymentAttempt
    {
        $paymentAttempt->refresh()->loadMissing(['paymentPlan.order.user', 'allocations.installment']);

        if (! $paymentAttempt->status->isActive()) {
            return $paymentAttempt;
        }

        if ($paymentAttempt->stripe_payment_intent_id !== null) {
            return $this->finalizePaymentAttempt->handle(
                $paymentAttempt,
                $this->stripeService->retrievePaymentIntent($paymentAttempt->stripe_payment_intent_id),
            );
        }

        try {
            $paymentIntent = $this->createPaymentIntent($paymentAttempt);
        } catch (ApiConnectionException $exception) {
            $paymentAttempt->update([
                'failure_reason' => 'Stripe could not be reached. This payment attempt will be reconciled automatically.',
            ]);

            throw $exception;
        } catch (CardException $exception) {
            $paymentIntent = $this->paymentIntentFromException($exception);

            if ($paymentIntent instanceof PaymentIntent) {
                return $this->finalizePaymentAttempt->handle($paymentAttempt, $paymentIntent);
            }

            return $this->finalizePaymentAttempt->failWithoutPaymentIntent(
                $paymentAttempt,
                $this->customerFailureReason($exception),
                $exception->getDeclineCode() ?: $exception->getStripeCode(),
                $this->stringValue(data_get($exception->getError(), 'advice_code')),
            );
        } catch (ApiErrorException $exception) {
            return $this->finalizePaymentAttempt->failWithoutPaymentIntent(
                $paymentAttempt,
                'We could not process this payment. Please review the payment method on your account.',
                $exception->getStripeCode(),
                $this->stringValue(data_get($exception->getError(), 'advice_code')),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->finalizePaymentAttempt->failWithoutPaymentIntent(
                $paymentAttempt,
                'We could not process this payment. Please review the payment method on your account.',
            );
        }

        return $this->finalizePaymentAttempt->handle(
            $paymentAttempt,
            $paymentIntent,
            recordFailure: $paymentAttempt->origin !== InstallmentPaymentAttemptOrigin::Customer,
        );
    }

    private function createPaymentIntent(InstallmentPaymentAttempt $paymentAttempt): PaymentIntent
    {
        $metadata = [
            'payment_attempt_id' => (string) $paymentAttempt->id,
            'payment_plan_id' => (string) $paymentAttempt->payment_plan_id,
            'order_id' => (string) $paymentAttempt->paymentPlan->order_id,
            'origin' => $paymentAttempt->origin->value,
        ];

        if ($paymentAttempt->origin === InstallmentPaymentAttemptOrigin::Customer) {
            $user = $paymentAttempt->paymentPlan->order?->user;

            if (! $user instanceof User) {
                throw new DomainException('The payment plan customer could not be found.');
            }

            $paymentIntent = $this->stripeService->createPaymentIntent(
                user: $user,
                amount: $paymentAttempt->total_amount,
                metadata: $metadata,
                setupFutureUsage: $paymentAttempt->use_for_future,
                idempotencyKey: $paymentAttempt->stripeIdempotencyKey(),
            );

            $stripeCustomerId = $this->stringValue($paymentIntent->customer ?? null);

            if ($stripeCustomerId === null) {
                throw new DomainException('Stripe did not return a customer for this payment.');
            }

            if ($paymentAttempt->stripe_customer_id !== $stripeCustomerId) {
                $paymentAttempt->update(['stripe_customer_id' => $stripeCustomerId]);
            }

            if ($paymentAttempt->paymentPlan->stripe_customer_id !== $stripeCustomerId) {
                $paymentAttempt->paymentPlan->update(['stripe_customer_id' => $stripeCustomerId]);
            }

            return $paymentIntent;
        }

        if (! is_string($paymentAttempt->stripe_payment_method_id)) {
            throw new DomainException('The payment plan does not have an assigned payment method.');
        }

        return $this->stripeService->chargePaymentMethod(
            customerId: (string) $paymentAttempt->stripe_customer_id,
            paymentMethodId: $paymentAttempt->stripe_payment_method_id,
            amount: $paymentAttempt->total_amount,
            description: "Missed installments for Order #{$paymentAttempt->paymentPlan->order_id}",
            metadata: $metadata,
            idempotencyKey: $paymentAttempt->stripeIdempotencyKey(),
        );
    }

    private function paymentIntentFromException(CardException $exception): ?PaymentIntent
    {
        $paymentIntent = data_get($exception->getError(), 'payment_intent');

        if ($paymentIntent instanceof PaymentIntent) {
            return $paymentIntent;
        }

        $paymentIntentId = $this->stringValue($paymentIntent);

        return $paymentIntentId === null
            ? null
            : $this->stripeService->retrievePaymentIntent($paymentIntentId);
    }

    private function customerFailureReason(CardException $exception): string
    {
        $message = $exception->getError()?->message;

        return is_string($message) && $message !== ''
            ? $message
            : 'The payment method was declined. Please use another payment method.';
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $id = data_get($value, 'id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
