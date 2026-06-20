<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\Events\Registered;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Enums\LayoutMode;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('uses the managed password reset email', function (): void {
    $user = User::factory()->create([
        'first_name' => 'Jordan',
        'email' => 'jordan@example.com',
    ]);

    $mail = (new ResetPassword('reset-token'))->toMail($user);

    expect($mail)->toBeInstanceOf(ManagedMail::class)
        ->and($mail->hasTo('jordan@example.com'))->toBeTrue()
        ->and($mail->getRenderedEmail()->html)->toContain('Jordan')
        ->toContain('Reset Password');
});

it('uses the user panel password reset link even when the admin panel is current', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create([
        'first_name' => 'Melissa',
        'email' => 'mmcurtiss88+test2@gmail.com',
    ]);

    $mail = (new ResetPassword('reset-token'))->toMail($user);

    expect($mail->getRenderedEmail()->html)
        ->toContain('/dancefam/password-reset/reset')
        ->not->toContain('/admin/password-reset/reset');
});

it('queues the managed welcome email after registration', function (): void {
    Mail::fake();

    $user = User::factory()->create(['first_name' => 'Jordan']);

    event(new Registered($user));

    Mail::assertQueued(
        ManagedMail::class,
        fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'user-welcome'
            && $mail->hasTo($user->email)
            && $mail->usesMailer('handcrafted'),
    );
});

it('does not queue a disabled managed welcome email', function (): void {
    Mail::fake();

    app(ManagedTemplateRepository::class)->saveOverride('user-welcome', [
        'layout_mode' => LayoutMode::Inherited,
        'is_active' => false,
    ]);

    event(new Registered(User::factory()->create(['first_name' => 'Jordan'])));

    Mail::assertNothingQueued();
});

it('does not send a disabled managed authentication email', function (): void {
    app(ManagedTemplateRepository::class)->saveOverride('user-password-reset', [
        'layout_mode' => LayoutMode::Inherited,
        'is_active' => false,
    ]);

    $mailer = app('mail.manager')->mailer('array');
    $transport = $mailer->getSymfonyTransport();
    $transport->flush();

    ManagedMail::make('user-password-reset')
        ->to('jordan@example.com')
        ->send($mailer);

    expect($transport->messages())->toBeEmpty();
});
