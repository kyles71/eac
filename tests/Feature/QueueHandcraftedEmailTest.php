<?php

declare(strict_types=1);

use App\Actions\Mail\QueueHandcraftedEmail;
use App\Mail\HandcraftedEmail;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Enums\LayoutMode;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

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

it('does not queue handcrafted emails when the registered type is disabled', function (): void {
    Mail::fake();

    app(ManagedTemplateRepository::class)->saveOverride('handcrafted', [
        'layout_mode' => LayoutMode::Inherited,
        'is_active' => false,
    ]);

    $queued = app(QueueHandcraftedEmail::class)->handle(
        recipients: ['first@example.com'],
        subject: 'Class update',
        body: 'See you soon.',
        deliveryMode: QueueHandcraftedEmail::DELIVERY_MODE_INDIVIDUAL,
        archiveTo: [],
    );

    expect($queued)->toBeFalse();
    Mail::assertNothingQueued();
});
