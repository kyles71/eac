<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Models\Enrollment;
use App\Models\User;
use App\Services\Mail\OpenEnrollmentReminderContent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class SendOpenEnrollmentReminders
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private OpenEnrollmentReminderContent $content,
    ) {}

    /** @return array{users_reminded: int, enrollments_marked: int} */
    public function handle(?CarbonInterface $dateTime = null): array
    {
        $cutoff = CarbonImmutable::instance($dateTime ?? now())->subWeek();
        $usersReminded = 0;
        $enrollmentsMarked = 0;

        User::query()
            ->whereHas('enrollments', fn (Builder $query): Builder => $query
                ->whereNull('student_id')
                ->whereNull('assignment_reminder_sent_at')
                ->where('created_at', '<=', $cutoff))
            ->lazyById()
            ->each(function (User $user) use ($cutoff, &$usersReminded, &$enrollmentsMarked): void {
                if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    return;
                }

                $enrollments = Enrollment::query()
                    ->where('user_id', $user->id)
                    ->whereNull('student_id')
                    ->whereNull('assignment_reminder_sent_at')
                    ->where('created_at', '<=', $cutoff)
                    ->with('course')
                    ->get();

                if ($enrollments->isEmpty()) {
                    return;
                }

                $payload = $this->content->for($user, $enrollments);

                if (! $this->managedEmail->handle(
                    recipients: $user->email,
                    emailTypeKey: 'open-enrollment-reminder',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                )) {
                    return;
                }

                $marked = Enrollment::query()
                    ->whereKey($enrollments->modelKeys())
                    ->whereNull('student_id')
                    ->whereNull('assignment_reminder_sent_at')
                    ->update(['assignment_reminder_sent_at' => now()]);

                $usersReminded++;
                $enrollmentsMarked += $marked;
            });

        return [
            'users_reminded' => $usersReminded,
            'enrollments_marked' => $enrollmentsMarked,
        ];
    }
}
