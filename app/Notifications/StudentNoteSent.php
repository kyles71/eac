<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

final class StudentNoteSent extends Notification
{
    public function __construct(
        public readonly int $studentCommunicationId,
        public readonly string $subject,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'student_communication_id' => $this->studentCommunicationId,
            'subject' => $this->subject,
        ];
    }
}
