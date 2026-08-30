<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentEmailAudience;
use App\Models\AcademicTerm;
use App\Models\Enrollment;
use App\Models\Student;

final readonly class CurrentEnrollmentEmailRecipientsService
{
    public function currentTerm(): ?AcademicTerm
    {
        return AcademicTerm::query()
            ->current()
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    public function forAudience(
        EnrollmentEmailAudience $audience,
        ?AcademicTerm $academicTerm = null,
    ): array {
        $academicTerm ??= $this->currentTerm();

        if (! $academicTerm instanceof AcademicTerm) {
            return [];
        }

        return match ($audience) {
            EnrollmentEmailAudience::UserAccounts => $this->userAccountEmails($academicTerm),
            EnrollmentEmailAudience::StudentEmails => $this->studentEmails($academicTerm),
        };
    }

    public function countForAudience(EnrollmentEmailAudience $audience): int
    {
        return count($this->forAudience($audience));
    }

    /** @return array<int, string> */
    private function userAccountEmails(AcademicTerm $academicTerm): array
    {
        return $this->uniqueEmails(
            Enrollment::query()
                ->whereHas('course', fn ($query) => $query->where('academic_term_id', $academicTerm->id))
                ->with('user:id,email')
                ->get()
                ->pluck('user.email')
                ->all(),
        );
    }

    /** @return array<int, string> */
    private function studentEmails(AcademicTerm $academicTerm): array
    {
        $emails = Enrollment::query()
            ->whereNotNull('student_id')
            ->whereHas('course', fn ($query) => $query->where('academic_term_id', $academicTerm->id))
            ->with([
                'student.additionalEmails:id,student_id,email',
                'student.user:id,email',
            ])
            ->get()
            ->flatMap(function (Enrollment $enrollment): array {
                $student = $enrollment->student;

                if (! $student instanceof Student) {
                    return [];
                }

                return [
                    $student->user?->email,
                    ...$student->additionalEmails->pluck('email')->all(),
                ];
            })
            ->all();

        return $this->uniqueEmails($emails);
    }

    /**
     * @param  array<int, mixed>  $emails
     * @return array<int, string>
     */
    private function uniqueEmails(array $emails): array
    {
        $uniqueEmails = [];

        foreach ($emails as $email) {
            if (! is_string($email)) {
                continue;
            }

            $email = mb_trim($email);

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $uniqueEmails[mb_strtolower($email)] ??= $email;
        }

        return array_values($uniqueEmails);
    }
}
