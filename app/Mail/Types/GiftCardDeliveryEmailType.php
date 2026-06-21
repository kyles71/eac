<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class GiftCardDeliveryEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'gift-card-delivery',
            names: ['en' => 'Gift Card Delivery'],
            description: 'Sent once for each gift card created by a completed purchase.',
            category: 'transactional',
            subjects: ['en' => 'Your {{ gift_card.value }} gift card from {{ app.name }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ purchaser.first_name }},</p>
                <p>Thank you for purchasing a gift card from {{ app.name }}.</p>
                <p><strong>Gift card code:</strong> {{ gift_card.code }}</p>
                <p><strong>Value:</strong> {{ gift_card.value }}</p>
                <p><strong>Restrictions:</strong> {{ gift_card.restrictions }}</p>
                <p><strong>Order:</strong> #{{ order.number }} on {{ order.date }}</p>
                <p>Use the button below to open the Credits &amp; Gift Cards tab on your Billing page, then choose Redeem Gift Card and enter the code above.</p>
                {{ slot.redeem-action }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('purchaser.first_name', 'Purchaser first name', example: 'Jamie'),
                new Token('purchaser.full_name', 'Purchaser full name', example: 'Jamie Dancer'),
                new Token('purchaser.email', 'Purchaser email address', example: 'jamie@example.com'),
                new Token('gift_card.code', 'Unique gift card code', example: 'ABCD1234EFGH5678'),
                new Token('gift_card.value', 'Gift card value', example: '$50.00'),
                new Token('gift_card.restrictions', 'Gift card restriction summary', example: 'Unrestricted'),
                new Token('order.number', 'Order number', example: '1234'),
                new Token('order.date', 'Order date', example: 'June 20, 2026'),
            ],
            slots: [
                new SystemSlot(
                    key: 'redeem-action',
                    label: 'Redeem gift card action',
                    previewHtml: '<p><a href="#">Redeem Gift Card</a></p>',
                ),
            ],
        );
    }
}
