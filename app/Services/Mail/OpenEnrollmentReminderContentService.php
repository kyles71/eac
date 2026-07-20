<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final readonly class OpenEnrollmentReminderContentService
{
    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(User $user, Collection $enrollments): array
    {
        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $user->first_name,
                'user.full_name' => mb_trim("{$user->first_name} {$user->last_name}"),
                'user.email' => $user->email,
                'open_enrollments.count' => (string) $enrollments->count(),
                'open_enrollments.label' => Str::plural('enrollment', $enrollments->count()),
            ],
            'slots' => [
                'open-enrollments' => view('mail.open-enrollment-reminder-details', [
                    'enrollments' => $enrollments,
                ])->render(),
            ],
        ];
    }
}
