<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\MailManager;

final readonly class QueueManagedEmail
{
    /**
     * @param  string|list<string>  $recipients
     * @param  array<string, mixed>  $tokens
     * @param  array<string, string>  $slots
     * @param  array<string, bool>  $conditions
     */
    public function handle(
        string|array $recipients,
        string $emailTypeKey,
        array $tokens = [],
        array $slots = [],
        array $conditions = [],
        string $mailer = 'transactional',
    ): bool {
        if (! app(MailManager::class)->isEnabled($emailTypeKey)) {
            return false;
        }

        Mail::mailer($mailer)
            ->to($recipients)
            ->queue(
                ManagedMail::make($emailTypeKey)
                    ->tokens($tokens)
                    ->slots($slots)
                    ->conditions($conditions)
                    ->afterCommit(),
            );

        return true;
    }
}
