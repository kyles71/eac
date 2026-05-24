<?php

declare(strict_types=1);

use App\Filament\Actions\SendEmailAction;
use App\Mail\HandcraftedEmail;
use Illuminate\Support\Facades\Mail;

it('has the correct default name', function (): void {
    expect(SendEmailAction::getDefaultName())->toBe('sendEmail');
});

it('can set default to addresses from an array', function (): void {
    $emails = ['test@example.com', 'admin@example.com'];

    $action = SendEmailAction::make()
        ->to($emails);

    expect($action)->toBeInstanceOf(SendEmailAction::class);
});

it('can set default to addresses from a closure', function (): void {
    $emails = ['closure@example.com', 'dynamic@example.com'];

    $action = SendEmailAction::make()
        ->to(fn (): array => $emails);

    expect($action)->toBeInstanceOf(SendEmailAction::class);
});

it('queues a handcrafted email using the handcrafted mailer', function (): void {
    Mail::fake();

    $recipients = ['recipient@example.com'];
    $subject = 'Test Subject';
    $body = 'Test email body content';

    SendEmailAction::make()->call([
        'data' => [
            'to' => $recipients,
            'subject' => $subject,
            'body' => $body,
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, function (HandcraftedEmail $mail) use ($recipients, $subject, $body): bool {
        return $mail->hasTo($recipients[0])
            && $mail->usesMailer('handcrafted')
            && $mail->emailSubject === $subject
            && $mail->emailBody === $body;
    });
});

it('queues one handcrafted email per recipient by default', function (): void {
    Mail::fake();

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com', 'FIRST@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 2);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && ! $mail->hasTo('second@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('second@example.com')
        && ! $mail->hasTo('first@example.com'));
});

it('uses the configured handcrafted delivery mode', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.delivery_mode', SendEmailAction::DELIVERY_MODE_GROUPED);

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 1);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && $mail->hasTo('second@example.com'));
});

it('can queue one handcrafted email to all recipients when grouped mode is set on the action', function (): void {
    Mail::fake();

    SendEmailAction::make()
        ->deliveryMode(SendEmailAction::DELIVERY_MODE_GROUPED)
        ->call([
            'data' => [
                'to' => ['first@example.com', 'second@example.com'],
                'subject' => 'Class update',
                'body' => 'See you soon.',
            ],
        ]);

    Mail::assertQueued(HandcraftedEmail::class, 1);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && $mail->hasTo('second@example.com'));
});

it('uses bcc archive copies for non-Textmagic mailers', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.transport', 'log');
    config()->set('mail.mailers.handcrafted.archive_to', 'archive@example.com');

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 2);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && $mail->hasBcc('archive@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('second@example.com')
        && $mail->hasBcc('archive@example.com'));
});

it('can override archive recipients for a send action instance', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.archive_to', '');

    SendEmailAction::make()
        ->archiveTo(['archive@example.com'])
        ->call([
            'data' => [
                'to' => ['first@example.com'],
                'subject' => 'Class update',
                'body' => 'See you soon.',
            ],
        ]);

    Mail::assertQueued(HandcraftedEmail::class, 1);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && $mail->hasBcc('archive@example.com'));
});

it('uses a separate archive email for Textmagic mailers', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.transport', 'textmagic');
    config()->set('mail.mailers.handcrafted.archive_to', 'archive@example.com');

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 3);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && ! $mail->hasBcc('archive@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('second@example.com')
        && ! $mail->hasBcc('archive@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('archive@example.com')
        && ! $mail->hasTo('first@example.com')
        && ! $mail->hasTo('second@example.com'));
});

it('does not queue an archive copy when archive recipients are empty', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.archive_to', '');

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 2);
});

it('can disable archive copies for a send action instance', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.archive_to', 'archive@example.com');

    SendEmailAction::make()
        ->withoutArchiveCopy()
        ->call([
            'data' => [
                'to' => ['first@example.com'],
                'subject' => 'Class update',
                'body' => 'See you soon.',
            ],
        ]);

    Mail::assertQueued(HandcraftedEmail::class, 1);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && ! $mail->hasBcc('archive@example.com'));
});
