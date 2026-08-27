<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Mail\QueueHandcraftedEmail;
use App\Actions\Students\SendStudentCustomEmail;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Support\Filament\EmailComposer;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * Filament action for composing and queueing handcrafted emails.
 *
 * Usage options:
 * - to(array|Closure $to): Sets the default recipients shown in the modal.
 * - archiveTo(array|Closure|null $recipients): Overrides the configured archive/self-copy recipients.
 *       Textmagic sends archive recipients as a separate message; other mailers receive archive copies by BCC.
 * - withoutArchiveCopy(): Disables archive/self-copy recipients for this action instance.
 */
final class SendEmailAction extends BaseEmailAction
{
    protected Student|Closure|null $student = null;

    protected Event|Closure|null $studentEvent = null;

    /**
     * @var array<int, string>|Closure(): array<int, string>|null
     */
    protected array|Closure|null $archiveTo = null;

    protected bool $hasArchiveToOverride = false;

    protected bool $archiveCopyEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Send Email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize('Send:Email')
            ->slideOver(false)
            ->schema(fn (): array => [
                $this->recipientSelect(),
                EmailComposer::subject(),
                EmailComposer::body(),
            ])
            ->action(function (array $data): void {
                $queued = $this->queueEmails($data);
                $notification = Notification::make()
                    ->title($queued ? 'Email queued' : 'Handcrafted email is disabled');

                ($queued ? $notification->success() : $notification->warning())->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'sendEmail';
    }

    public function forStudent(Student|Closure $student, Event|Closure|null $event = null): static
    {
        $this->student = $student;
        $this->studentEvent = $event;
        $this->to($student);

        return $this;
    }

    /**
     * Override the configured archive/self-copy recipients for this action instance.
     *
     * @param  array<int, string>|Closure(): array<int, string>|null  $recipients
     */
    public function archiveTo(array|Closure|null $recipients): static
    {
        $this->archiveTo = $recipients;
        $this->hasArchiveToOverride = true;

        return $this;
    }

    /**
     * Disable archive/self-copy recipients for this action instance.
     */
    public function withoutArchiveCopy(): static
    {
        $this->archiveCopyEnabled = false;

        return $this;
    }

    /**
     * @param  array{to?: mixed, subject?: mixed, body?: mixed}  $data
     */
    private function queueEmails(array $data): bool
    {
        $recipients = $this->resolveRecipients($data['to'] ?? []);
        $student = $this->evaluate($this->student);

        if ($student instanceof Student) {
            $user = $this->authenticatedUser()
                ?? throw new LogicException('Student emails require an authenticated user.');
            $event = $this->evaluate($this->studentEvent);

            return app(SendStudentCustomEmail::class)->handle(
                student: $student,
                author: $user,
                event: $event instanceof Event ? $event : null,
                subject: (string) ($data['subject'] ?? ''),
                body: (string) ($data['body'] ?? ''),
                recipientEmails: $recipients,
                archiveTo: $this->getArchiveTo(),
            ) instanceof StudentCommunication;
        }

        return app(QueueHandcraftedEmail::class)->handle(
            recipients: $recipients,
            subject: (string) ($data['subject'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            deliveryMode: QueueHandcraftedEmail::DELIVERY_MODE_INDIVIDUAL,
            archiveTo: $this->getArchiveTo(),
        );
    }

    private function getArchiveTo(): mixed
    {
        if (! $this->archiveCopyEnabled) {
            return [];
        }

        if ($this->hasArchiveToOverride) {
            if ($this->archiveTo === null) {
                return [];
            }

            return $this->evaluate($this->archiveTo);
        }

        return config('mail.mailers.handcrafted.archive_to', []);
    }
}
