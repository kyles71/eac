<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\Mail\PaymentPlanScheduleAdjustedContentService;
use Kyle\FilamentMailManager\MailManager;
use Throwable;

final readonly class PaymentPlanScheduleEmailAvailabilityService
{
    public const string EMAIL_TYPE_KEY = 'payment-plan-schedule-adjusted';

    public function __construct(
        private MailManager $mailManager,
        private PaymentPlanScheduleAdjustedContentService $content,
    ) {}

    /** @return array{available: bool, reason: ?string} */
    public function for(PaymentPlan $paymentPlan, string $reason): array
    {
        $paymentPlan->loadMissing('order.user');
        $user = $paymentPlan->order?->user;

        if (! $user instanceof User || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return [
                'available' => false,
                'reason' => 'The customer does not have a valid email address.',
            ];
        }

        if (! is_array(config('mail.mailers.transactional'))) {
            return [
                'available' => false,
                'reason' => 'The transactional mailer is not configured.',
            ];
        }

        try {
            if (! $this->mailManager->isEnabled(self::EMAIL_TYPE_KEY)) {
                return [
                    'available' => false,
                    'reason' => 'The payment schedule adjustment email is disabled.',
                ];
            }

            $payload = $this->content->for($paymentPlan, $reason);
            $this->mailManager->render(
                emailTypeKey: self::EMAIL_TYPE_KEY,
                tokens: $payload['tokens'],
                slots: $payload['slots'],
            );
        } catch (Throwable $exception) {
            report($exception);

            return [
                'available' => false,
                'reason' => 'The payment schedule adjustment email could not be rendered.',
            ];
        }

        return ['available' => true, 'reason' => null];
    }
}
