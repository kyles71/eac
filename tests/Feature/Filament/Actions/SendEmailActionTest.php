<?php

declare(strict_types=1);

use App\Filament\Actions\SendEmailAction;
use App\Mail\GenericMail;
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

it('sends an email using GenericMail', function (): void {
    Mail::fake();

    $recipients = ['recipient@example.com'];
    $subject = 'Test Subject';
    $body = 'Test email body content';

    Mail::to($recipients)
        ->send(new GenericMail(
            emailSubject: $subject,
            emailBody: $body,
        ));

    Mail::assertSent(GenericMail::class, function (GenericMail $mail) use ($recipients, $subject): bool {
        return $mail->hasTo($recipients[0])
            && $mail->emailSubject === $subject;
    });
});
