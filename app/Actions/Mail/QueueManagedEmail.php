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
        mixed $archiveTo = [],
    ): bool {
        if (! app(MailManager::class)->isEnabled($emailTypeKey)) {
            return false;
        }

        $recipients = $this->normalizeRecipients($recipients);
        $archiveRecipients = array_values(array_diff(
            $this->normalizeRecipients($archiveTo),
            $recipients,
        ));
        $pendingMail = Mail::mailer($mailer)->to($recipients);

        if ($archiveRecipients !== [] && ! $this->usesTextmagicTransport($mailer)) {
            $pendingMail->bcc($archiveRecipients);
        }

        $pendingMail->queue($this->mail(
            emailTypeKey: $emailTypeKey,
            tokens: $tokens,
            slots: $slots,
            conditions: $conditions,
        ));

        if ($archiveRecipients !== [] && $this->usesTextmagicTransport($mailer)) {
            Mail::mailer($mailer)
                ->to($archiveRecipients)
                ->queue($this->mail(
                    emailTypeKey: $emailTypeKey,
                    tokens: $tokens,
                    slots: $slots,
                    conditions: $conditions,
                ));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  array<string, string>  $slots
     * @param  array<string, bool>  $conditions
     */
    private function mail(string $emailTypeKey, array $tokens, array $slots, array $conditions): ManagedMail
    {
        return ManagedMail::make($emailTypeKey)
            ->tokens($tokens)
            ->slots($slots)
            ->conditions($conditions)
            ->afterCommit();
    }

    /** @return list<string> */
    private function normalizeRecipients(mixed $recipients): array
    {
        if (is_string($recipients)) {
            $recipients = str_getcsv($recipients);
        }

        if (! is_array($recipients)) {
            return [];
        }

        return collect($recipients)
            ->filter(fn (mixed $recipient): bool => is_string($recipient) && filled($recipient))
            ->map(fn (string $recipient): string => mb_strtolower(mb_trim($recipient)))
            ->unique()
            ->values()
            ->all();
    }

    private function usesTextmagicTransport(string $mailer): bool
    {
        return config("mail.mailers.{$mailer}.transport") === 'textmagic';
    }
}
