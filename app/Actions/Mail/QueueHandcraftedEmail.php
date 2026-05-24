<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Mail\HandcraftedEmail;
use Illuminate\Support\Facades\Mail;

final class QueueHandcraftedEmail
{
    public const string DELIVERY_MODE_GROUPED = 'grouped';

    public const string DELIVERY_MODE_INDIVIDUAL = 'individual';

    public function handle(
        mixed $recipients,
        string $subject,
        string $body,
        mixed $deliveryMode,
        mixed $archiveTo,
    ): void {
        $recipients = $this->normalizeRecipients($recipients);
        $archiveRecipients = $this->normalizeRecipients($archiveTo);

        match ($this->normalizeDeliveryMode($deliveryMode)) {
            self::DELIVERY_MODE_GROUPED => $this->queueGroupedEmail(
                $recipients,
                $subject,
                $body,
                $this->archiveRecipientsFor($recipients, $archiveRecipients),
            ),
            default => $this->queueIndividualEmails($recipients, $subject, $body, $archiveRecipients),
        };

        $separateArchiveRecipients = $this->archiveRecipientsFor($recipients, $archiveRecipients);

        if ($this->usesSeparateArchiveEmail($separateArchiveRecipients)) {
            $this->queueEmail($separateArchiveRecipients, $subject, $body);
        }
    }

    /**
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $archiveRecipients
     */
    private function queueIndividualEmails(array $recipients, string $subject, string $body, array $archiveRecipients): void
    {
        foreach ($recipients as $recipient) {
            $this->queueEmail(
                [$recipient],
                $subject,
                $body,
                $this->archiveRecipientsFor([$recipient], $archiveRecipients),
            );
        }
    }

    /**
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $archiveRecipients
     */
    private function queueGroupedEmail(array $recipients, string $subject, string $body, array $archiveRecipients): void
    {
        $this->queueEmail($recipients, $subject, $body, $archiveRecipients);
    }

    /**
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $bcc
     */
    private function queueEmail(array $recipients, string $subject, string $body, array $bcc = []): void
    {
        $pendingMail = Mail::mailer('handcrafted')
            ->to($recipients);

        if ($this->usesBccArchiveCopy($bcc)) {
            $pendingMail->bcc($bcc);
        }

        $pendingMail->queue(new HandcraftedEmail(
            emailSubject: $subject,
            emailBody: $body,
        ));
    }

    private function normalizeDeliveryMode(mixed $deliveryMode): string
    {
        if (! is_string($deliveryMode)) {
            return self::DELIVERY_MODE_INDIVIDUAL;
        }

        return match (mb_strtolower(mb_trim($deliveryMode))) {
            self::DELIVERY_MODE_GROUPED => self::DELIVERY_MODE_GROUPED,
            default => self::DELIVERY_MODE_INDIVIDUAL,
        };
    }

    /**
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $archiveRecipients
     * @return array<int, string>
     */
    private function archiveRecipientsFor(array $recipients, array $archiveRecipients): array
    {
        return $this->rejectRecipients($archiveRecipients, $recipients);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRecipients(mixed $recipients): array
    {
        if (is_string($recipients)) {
            $recipients = str_getcsv($recipients);
        }

        if ($recipients === null) {
            return [];
        }

        if (! is_array($recipients)) {
            $recipients = [$recipients];
        }

        $normalized = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            if (! is_string($recipient)) {
                continue;
            }

            $email = mb_trim($recipient);

            if ($email === '') {
                continue;
            }

            $key = mb_strtolower($email);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $email;
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $rejectedRecipients
     * @return array<int, string>
     */
    private function rejectRecipients(array $recipients, array $rejectedRecipients): array
    {
        $rejected = array_flip(array_map(
            static fn (string $recipient): string => mb_strtolower($recipient),
            $rejectedRecipients,
        ));

        return array_values(array_filter(
            $recipients,
            static fn (string $recipient): bool => ! isset($rejected[mb_strtolower($recipient)]),
        ));
    }

    /**
     * @param  array<int, string>  $archiveRecipients
     */
    private function usesSeparateArchiveEmail(array $archiveRecipients): bool
    {
        return $archiveRecipients !== [] && $this->usesTextmagicTransport();
    }

    /**
     * @param  array<int, string>  $archiveRecipients
     */
    private function usesBccArchiveCopy(array $archiveRecipients): bool
    {
        return $archiveRecipients !== [] && ! $this->usesTextmagicTransport();
    }

    private function usesTextmagicTransport(): bool
    {
        return config('mail.mailers.handcrafted.transport') === 'textmagic';
    }
}
