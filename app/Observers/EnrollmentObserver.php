<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\Enrollment;
use App\Models\Student;

final readonly class EnrollmentObserver
{
    public function __construct(private ReconcileRequiredForms $reconcileRequiredForms) {}

    public function saved(Enrollment $enrollment): void
    {
        $this->reconcileStudent($enrollment->student_id);

        if ($enrollment->wasChanged('student_id')) {
            $this->reconcileStudent($enrollment->getOriginal('student_id'));
        }
    }

    public function deleted(Enrollment $enrollment): void
    {
        $this->reconcileStudent($enrollment->student_id);
    }

    private function reconcileStudent(mixed $studentId): void
    {
        if (! is_numeric($studentId)) {
            return;
        }

        $student = Student::query()->find((int) $studentId);

        if ($student !== null) {
            $this->reconcileRequiredForms->handle($student);
        }
    }
}
