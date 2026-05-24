<?php

declare(strict_types=1);

use App\Mail\Transports\TextmagicTransport;
use Illuminate\Support\Facades\Mail;

it('defines provider-neutral mailers that stay on log by default', function (): void {
    expect(config('mail.mailers.transactional.transport'))->toBe('log')
        ->and(config('mail.mailers.handcrafted.transport'))->toBe('log')
        ->and(config('mail.mailers.transactional.textmagic'))->toHaveKeys(['sender_id', 'from_name', 'reply_to'])
        ->and(config('mail.mailers.handcrafted.textmagic'))->toHaveKeys(['sender_id', 'from_name', 'reply_to']);
});

it('can create the Textmagic transport from a provider-neutral mailer config', function (): void {
    config()->set('services.textmagic.username', 'textmagic-user');
    config()->set('services.textmagic.api_key', 'textmagic-key');
    config()->set('mail.mailers.handcrafted.transport', 'textmagic');
    config()->set('mail.mailers.handcrafted.textmagic.sender_id', 123);
    config()->set('mail.mailers.handcrafted.textmagic.from_name', 'Admin Team');
    config()->set('mail.mailers.handcrafted.textmagic.reply_to', 'reply@example.com');

    Mail::forgetMailers();

    expect(Mail::createSymfonyTransport(config('mail.mailers.handcrafted')))
        ->toBeInstanceOf(TextmagicTransport::class);
});
