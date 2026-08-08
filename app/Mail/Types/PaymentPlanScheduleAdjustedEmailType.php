<?php

declare(strict_types=1);

namespace App\Mail\Types;

use App\Services\PaymentPlanScheduleEmailAvailabilityService;
use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class PaymentPlanScheduleAdjustedEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: PaymentPlanScheduleEmailAvailabilityService::EMAIL_TYPE_KEY,
            names: ['en' => 'Payment Plan Schedule Adjusted'],
            description: 'Sent to a customer after an administrator changes payment plan due dates.',
            category: 'transactional',
            subjects: ['en' => 'Payment schedule updated for order #{{ order.number }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Your payment schedule for order #{{ order.number }} has been updated.</p>
                <p><strong>Reason:</strong> {{ adjustment.reason }}</p>
                <p>Automatic payments are attempted at 12:01 AM Eastern on each due date.</p>
                {{ slot.revised-schedule }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'Customer first name', example: 'Jamie'),
                new Token('user.full_name', 'Customer full name', example: 'Jamie Dancer'),
                new Token('adjustment.reason', 'Customer-visible adjustment reason', example: 'Moved to align with the requested paycheck date.'),
                new Token('payment_plan.number', 'Payment plan number', example: '42'),
                new Token('order.number', 'Order number', example: '1234'),
            ],
            slots: [
                new SystemSlot(
                    key: 'revised-schedule',
                    label: 'Revised payment schedule',
                    previewHtml: '<p>Installment amounts, due dates, and statuses appear here.</p>',
                ),
            ],
        );
    }
}
