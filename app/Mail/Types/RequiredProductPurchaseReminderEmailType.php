<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class RequiredProductPurchaseReminderEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'required-product-purchase-reminder',
            names: ['en' => 'Required Product Purchase Reminder'],
            description: 'Sent once when a household has one or more outstanding required product purchases.',
            category: 'transactional',
            subjects: ['en' => 'Reminder: purchase your required {{ required_products.label }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Please purchase the following required items:</p>
                {{ slot.required-products }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC Plié Portal'),
                new Token('user.first_name', 'User first name', example: 'Jamie'),
                new Token('user.full_name', 'User full name', example: 'Jamie Dancer'),
                new Token('user.email', 'User email address', example: 'jamie@example.com'),
                new Token('required_products.count', 'Number of products in this reminder', example: '2'),
                new Token('required_products.label', 'Correct singular or plural product label', example: 'products'),
            ],
            slots: [
                new SystemSlot(
                    key: 'required-products',
                    label: 'Required products',
                    previewHtml: '<p>The outstanding products and their purchase deadlines appear here.</p>',
                ),
            ],
        );
    }
}
