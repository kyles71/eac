<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mail\Types\AbandonedCartReminderEmailType;
use App\Mail\Types\EventCancellationEmailType;
use App\Mail\Types\EventReminderEmailType;
use App\Mail\Types\HandcraftedEmailType;
use App\Mail\Types\InstallmentPaymentFailedEmailType;
use App\Mail\Types\InstallmentPaymentSucceededEmailType;
use App\Mail\Types\OpenEnrollmentReminderEmailType;
use App\Mail\Types\OrderReceiptEmailType;
use App\Mail\Types\PasswordResetEmailType;
use App\Mail\Types\PastDueInstallmentEmailType;
use App\Mail\Types\VerifyEmailType;
use App\Mail\Types\WelcomeEmailType;
use App\Models\User;
use Filament\Auth\Events\Registered;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Kyle\FilamentMailManager\FilamentMailManager;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\MailManager;
use Throwable;

final class MailManagerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentMailManager::registerEmailTypes([
            AbandonedCartReminderEmailType::class,
            EventCancellationEmailType::class,
            EventReminderEmailType::class,
            HandcraftedEmailType::class,
            InstallmentPaymentFailedEmailType::class,
            InstallmentPaymentSucceededEmailType::class,
            OrderReceiptEmailType::class,
            OpenEnrollmentReminderEmailType::class,
            PastDueInstallmentEmailType::class,
            PasswordResetEmailType::class,
            VerifyEmailType::class,
            WelcomeEmailType::class,
        ]);

        ResetPassword::toMailUsing(fn (User $user, string $token): ManagedMail => ManagedMail::make('user-password-reset')
            ->to($user->getEmailForPasswordReset())
            ->tokens($this->userTokens($user))
            ->slots([
                'action' => $this->actionLink($this->passwordResetUrl($user, $token), 'Reset Password'),
            ]));

        VerifyEmail::toMailUsing(fn (User $user, string $url): ManagedMail => ManagedMail::make('user-verify-email')
            ->to($user->getEmailForVerification())
            ->tokens($this->userTokens($user))
            ->slots([
                'action' => $this->actionLink($url, 'Verify Email Address'),
            ]));

        Event::listen(Registered::class, function (Registered $event): void {
            $user = $event->getUser();

            if (! $user instanceof User) {
                return;
            }

            if (! app(MailManager::class)->isEnabled('user-welcome')) {
                return;
            }

            Mail::mailer('handcrafted')->to($user)->queue(
                ManagedMail::make('user-welcome')
                    ->tokens($this->userTokens($user)),
            );
        });
    }

    /**
     * @return array<string, string>
     */
    private function userTokens(User $user): array
    {
        return [
            'app.name' => config('app.name'),
            'user.first_name' => $user->first_name,
        ];
    }

    private function passwordResetUrl(User $user, string $token): string
    {
        try {
            return Filament::getPanel('user')->getResetPasswordUrl($token, $user);
        } catch (Throwable) {
            return url(route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false));
        }
    }

    private function actionLink(string $url, string $label): string
    {
        return '<a href="'.e($url).'" style="display:inline-block;padding:12px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px">'.e($label).'</a>';
    }
}
