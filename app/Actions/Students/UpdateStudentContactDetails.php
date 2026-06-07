<?php

declare(strict_types=1);

namespace App\Actions\Students;

use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class UpdateStudentContactDetails
{
    /**
     * @param  array<int, array{id?: int|string|null, email?: string|null, relationship_option?: string|null, relationship?: string|null}>  $additionalEmails
     */
    public function handle(Student $student, User $user, ?string $nickname, array $additionalEmails): void
    {
        if ($student->user_id !== $user->id) {
            throw new InvalidArgumentException('This student does not belong to your account.');
        }

        if (count($additionalEmails) > 3) {
            throw new InvalidArgumentException('A student may have no more than three additional emails.');
        }

        $normalizedEmails = collect($additionalEmails)
            ->map(fn (array $email): array => $this->normalizeEmail($email))
            ->values();

        if ($normalizedEmails->pluck('email')->duplicates()->isNotEmpty()) {
            throw new InvalidArgumentException('Additional email addresses must be unique for this student.');
        }

        DB::transaction(function () use ($student, $nickname, $normalizedEmails): void {
            $student->update(['nickname' => filled($nickname) ? $nickname : null]);

            $retainedEmailIds = [];

            foreach ($normalizedEmails as $emailData) {
                $emailId = filled($emailData['id']) ? (int) $emailData['id'] : null;
                $studentEmail = $emailId === null
                    ? new StudentEmail()
                    : StudentEmail::query()
                        ->where('student_id', $student->id)
                        ->find($emailId);

                if ($studentEmail === null) {
                    throw new InvalidArgumentException('An additional email could not be found for this student.');
                }

                $studentEmail->fill([
                    'email' => $emailData['email'],
                    'relationship' => $emailData['relationship'],
                ]);

                $student->additionalEmails()->save($studentEmail);
                $retainedEmailIds[] = $studentEmail->id;
            }

            $student->additionalEmails()
                ->when($retainedEmailIds !== [], fn ($query) => $query->whereNotIn('id', $retainedEmailIds))
                ->delete();
        });
    }

    /**
     * @param  array{id?: int|string|null, email?: string|null, relationship_option?: string|null, relationship?: string|null}  $email
     * @return array{id: int|string|null, email: string, relationship: string}
     */
    private function normalizeEmail(array $email): array
    {
        $relationshipOption = $email['relationship_option'] ?? null;
        $relationship = $relationshipOption === 'Other'
            ? mb_trim((string) ($email['relationship'] ?? ''))
            : (string) $relationshipOption;

        if (! in_array($relationshipOption, ['Mother', 'Father', 'Dancer', 'Other'], true)) {
            throw new InvalidArgumentException('The selected email relationship is invalid.');
        }

        if (blank($relationship)) {
            throw new InvalidArgumentException('Each additional email must have a relationship.');
        }

        $normalizedEmail = mb_strtolower(mb_trim((string) ($email['email'] ?? '')));

        if (! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Each additional email must be a valid email address.');
        }

        return [
            'id' => $email['id'] ?? null,
            'email' => $normalizedEmail,
            'relationship' => $relationship,
        ];
    }
}
