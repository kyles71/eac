<?php

declare(strict_types=1);

use App\Actions\Mail\QueueHandcraftedEmail;
use App\Mail\HandcraftedEmail;
use Illuminate\Support\Facades\Mail;

it('preserves grouped delivery capability for future non-private sends', function (): void {
    Mail::fake();

    app(QueueHandcraftedEmail::class)->handle(
        recipients: ['first@example.com', 'second@example.com'],
        subject: 'Class update',
        body: 'See you soon.',
        deliveryMode: QueueHandcraftedEmail::DELIVERY_MODE_GROUPED,
        archiveTo: [],
    );

    Mail::assertQueued(HandcraftedEmail::class, 1);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && $mail->hasTo('second@example.com'));
});
