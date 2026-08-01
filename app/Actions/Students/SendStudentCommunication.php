<?php

declare(strict_types=1);

namespace App\Actions\Students;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\StopLightColor;
use App\Enums\StudentCommunicationType;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use App\Services\Mail\StudentCommunicationContentService;
use App\Services\StudentCommunicationEventService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Kyle\FilamentMailManager\MailManager;

final readonly class SendStudentCommunication
{
    public function __construct(
        private StudentCommunicationEventService $events,
        private StudentCommunicationContentService $content,
        private QueueManagedEmail $managedEmail,
    ) {}

    /**
     * @param  list<string>  $recipientEmails
     */
    public function handle(
        Student $student,
        User $author,
        StudentCommunicationType $type,
        CarbonInterface|string $occurredAt,
        ?Event $event,
        ?StopLightColor $stopLightColor,
        string $note,
        array $recipientEmails,
    ): ?StudentCommunication {
        Gate::forUser($author)->authorize('view', $student);
        Gate::forUser($author)->authorize('create', StudentCommunication::class);

        $event = $event instanceof Event
            ? $this->events->findOrFail($student, $author, $event->id)
            : null;
        $note = mb_trim($note);
        $recipientEmails = $this->normalizeEmails($recipientEmails);

        if ($note === '') {
            throw new InvalidArgumentException('A communication note is required.');
        }

        if ($recipientEmails === []) {
            throw new InvalidArgumentException('At least one valid email recipient is required.');
        }

        if ($type === StudentCommunicationType::StopLight && ! $stopLightColor instanceof StopLightColor) {
            throw new InvalidArgumentException('A stop-light color is required.');
        }

        if (! app(MailManager::class)->isEnabled($type->emailTypeKey())) {
            return null;
        }

        $occurredAt = $occurredAt instanceof CarbonInterface
            ? CarbonImmutable::instance($occurredAt)->utc()
            : CarbonImmutable::parse($occurredAt, (string) config('app.timezone'))->utc();

        return DB::transaction(function () use (
            $student,
            $author,
            $type,
            $occurredAt,
            $event,
            $stopLightColor,
            $note,
            $recipientEmails,
        ): StudentCommunication {
            $communication = StudentCommunication::query()->create([
                'student_id' => $student->id,
                'event_id' => $event?->id,
                'author_id' => $author->id,
                'type' => $type,
                'stop_light_color' => $type === StudentCommunicationType::StopLight ? $stopLightColor : null,
                'occurred_at' => $occurredAt,
                'note' => $note,
                'recipient_emails' => $recipientEmails,
                'queued_at' => now(),
            ]);
            $payload = $this->content->for($communication);

            foreach ($recipientEmails as $recipientEmail) {
                if (! $this->managedEmail->handle(
                    recipients: $recipientEmail,
                    emailTypeKey: $type->emailTypeKey(),
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                    mailer: 'handcrafted',
                )) {
                    throw new DomainException('The communication email template is disabled.');
                }
            }

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
