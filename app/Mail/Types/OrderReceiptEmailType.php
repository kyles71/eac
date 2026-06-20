<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\ConditionalSection;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class OrderReceiptEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'order-receipt',
            names: ['en' => 'Order Receipt'],
            description: 'Sent after an order has been completed. Product-specific sections appear only when that product type was purchased.',
            category: 'transactional',
            subjects: ['en' => 'Receipt for order #{{ order.number }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Thank you for your purchase from {{ app.name }}. Here are the details for order #{{ order.number }} placed on {{ order.date }}.</p>
                {{ slot.order-details }}
                {{ conditional.course }}
                {{ conditional.costume }}
                {{ conditional.gift-card }}
                {{ conditional.standalone }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'User first name', example: 'Kyle'),
                new Token('order.number', 'Order number', example: '1234'),
                new Token('order.date', 'Order date', example: 'June 19, 2026'),
                new Token('order.total', 'Order total', example: '$125.00'),
            ],
            slots: [
                new SystemSlot(
                    key: 'order-details',
                    label: 'Order details',
                    previewHtml: '<p>Purchased items, totals, and payment plan details appear here.</p>',
                ),
            ],
            conditionalSections: [
                new ConditionalSection(
                    key: 'course',
                    label: 'Course purchase content',
                    contents: ['en' => '<p>Your course enrollment details are available in your account.</p>'],
                    description: 'Shown when the order contains at least one course.',
                ),
                new ConditionalSection(
                    key: 'costume',
                    label: 'Costume purchase content',
                    contents: ['en' => '<p>We will share any costume pickup details separately.</p>'],
                    description: 'Shown when the order contains at least one costume.',
                ),
                new ConditionalSection(
                    key: 'gift-card',
                    label: 'Gift card purchase content',
                    contents: ['en' => '<p>Your gift card details will be delivered separately.</p>'],
                    description: 'Shown when the order contains at least one gift card.',
                ),
                new ConditionalSection(
                    key: 'standalone',
                    label: 'Other product content',
                    contents: ['en' => '<p>We will share any additional fulfillment details separately.</p>'],
                    description: 'Shown when the order contains at least one standalone product.',
                ),
            ],
        );
    }
}
