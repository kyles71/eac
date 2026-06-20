<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class ProductPurchaseNotificationEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'product-purchase-notification',
            names: ['en' => 'Product Purchase Notification'],
            description: 'Sent to EAC after an order containing a product opted into purchase notifications is completed.',
            category: 'transactional',
            subjects: ['en' => 'Product purchase in order #{{ order.number }}'],
            bodies: ['en' => <<<'HTML'
                <p>A product configured for purchase notifications has been purchased.</p>
                <p><strong>Purchaser:</strong> {{ purchaser.name }} ({{ purchaser.email }})</p>
                {{ slot.purchase-details }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC Portal'),
                new Token('purchaser.name', 'Purchaser name', example: 'Kyle Smith'),
                new Token('purchaser.email', 'Purchaser email', example: 'kyle@example.com'),
                new Token('order.number', 'Order number', example: '1234'),
                new Token('order.date', 'Order date', example: 'June 20, 2026'),
                new Token('order.total', 'Order total', example: '$125.00'),
            ],
            slots: [
                new SystemSlot(
                    key: 'purchase-details',
                    label: 'Purchase details and purchaser answers',
                    previewHtml: '<h2>Order #1234</h2><p><strong>Competition Shirt</strong> — Qty 2</p><p><strong>Item 1</strong><br>Size: Medium</p>',
                ),
            ],
        );
    }
}
