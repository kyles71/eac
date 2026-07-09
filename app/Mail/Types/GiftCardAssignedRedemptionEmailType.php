<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class GiftCardAssignedRedemptionEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'gift-card-assigned-redemption',
            names: ['en' => 'Gift Card Assigned Redemption'],
            description: 'Sent when an admin assigns and redeems an unredeemed gift card into a recipient account.',
            category: 'transactional',
            subjects: ['en' => '{{ gift_card.value }} in store credit has been added to your {{ app.name }} account'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ recipient.first_name }},</p>
                <p>A gift card from {{ purchaser.full_name }} has been redeemed into store credit on your {{ app.name }} account.</p>
                <p><strong>Gift card code:</strong> {{ gift_card.code }}</p>
                <p><strong>Value:</strong> {{ gift_card.value }}</p>
                <p><strong>Restrictions:</strong> {{ gift_card.restrictions }}</p>
                <p><strong>Redeemed:</strong> {{ gift_card.redemption_date }}</p>
                <p>You can view the credit from the Credits &amp; Gift Cards tab on your Billing page.</p>
                {{ slot.billing-action }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('recipient.first_name', 'Recipient first name', example: 'Alex'),
                new Token('recipient.full_name', 'Recipient full name', example: 'Alex Dancer'),
                new Token('recipient.email', 'Recipient email address', example: 'alex@example.com'),
                new Token('purchaser.first_name', 'Purchaser first name', example: 'Jamie'),
                new Token('purchaser.full_name', 'Purchaser full name', example: 'Jamie Dancer'),
                new Token('purchaser.email', 'Purchaser email address', example: 'jamie@example.com'),
                new Token('gift_card.code', 'Unique gift card code', example: 'ABCD1234EFGH5678'),
                new Token('gift_card.value', 'Gift card value', example: '$50.00'),
                new Token('gift_card.restrictions', 'Gift card restriction summary', example: 'Unrestricted'),
                new Token('gift_card.redemption_date', 'Gift card redemption date', example: 'July 9, 2026'),
            ],
            slots: [
                new SystemSlot(
                    key: 'billing-action',
                    label: 'Billing credits action',
                    previewHtml: '<p><a href="#">View Store Credit</a></p>',
                ),
            ],
        );
    }
}
