<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Models\CourseHold;
use App\Services\Mail\CourseHoldContentService;

final readonly class SendCourseHoldEmail
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private CourseHoldContentService $content,
    ) {}

    public function handle(CourseHold $hold, string $emailTypeKey): bool
    {
        $hold->loadMissing('user');

        if (! filter_var($hold->user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $payload = $this->content->for($hold);

        return $this->managedEmail->handle(
            recipients: $hold->user->email,
            emailTypeKey: $emailTypeKey,
            tokens: $payload['tokens'],
            slots: $payload['slots'],
        );
    }
}
