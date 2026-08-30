<?php

declare(strict_types=1);

namespace App\Actions\Students;

use App\Actions\Mail\QueueHandcraftedEmail;
use App\Enums\StudentCommunicationType;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use App\Services\StudentCommunicationEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Kyle\FilamentMailManager\MailManager;

final readonly class SendStudentCustomEmail
{
    public function __construct(
        private StudentCommunicationEventService $events,
        private QueueHandcraftedEmail $email,
    ) {}

    /**
     * @param  list<string>  $recipientEmails
     */
    public function handle(
        Student $student,
        User $author,
        ?Event $event,
        string $subject,
        string $body,
        array $recipientEmails,
        mixed $archiveTo,
    ): ?StudentCommunication {
        Gate::forUser($author)->authorize('view', $student);
        Gate::forUser($author)->authorize('create', StudentCommunication::class);

        $event = $event instanceof Event
            ? $this->events->findOrFail($student, $author, $event->id)
            : null;
        $subject = mb_trim($subject);
        $body = mb_trim($body);
        $recipientEmails = $this->normalizeEmails($recipientEmails);

        if ($subject === '') {
            throw new InvalidArgumentException('An email subject is required.');
        }

        if ($body === '') {
            throw new InvalidArgumentException('An email body is required.');
        }

        if ($recipientEmails === []) {
            throw new InvalidArgumentException('At least one valid email recipient is required.');
        }

        if (! app(MailManager::class)->isEnabled(StudentCommunicationType::CustomEmail->emailTypeKey())) {
            return null;
        }

        return DB::transaction(function () use (
            $student,
            $author,
            $event,
            $subject,
            $body,
            $recipientEmails,
            $archiveTo,
        ): StudentCommunication {
            $communication = StudentCommunication::query()->create([
                'student_id' => $student->id,
                'event_id' => $event?->id,
                'author_id' => $author->id,
                'type' => StudentCommunicationType::CustomEmail,
                'first_aid_type' => null,
                'stop_light_color' => null,
                'occurred_at' => now(),
                'subject' => $subject,
                'note' => $body,
                'recipient_emails' => $recipientEmails,
                'queued_at' => now(),
            ]);

            $this->email->handle(
                recipients: $recipientEmails,
                subject: $subject,
                body: $body,
                deliveryMode: QueueHandcraftedEmail::DELIVERY_MODE_INDIVIDUAL,
                archiveTo: $archiveTo,
            );

            return $communication;
        });
    }

    /**
     * @param  array<int, mixed>  $emails
     * @return list<string>
     */
    private function normalizeEmails(array $emails): array
    {
        $normalized = [];

        foreach ($emails as $email) {
            if (! is_string($email) || ! filter_var($email = mb_trim($email), FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $normalized[mb_strtolower($email)] ??= $email;
        }

        return array_values($normalized);
    }
}
