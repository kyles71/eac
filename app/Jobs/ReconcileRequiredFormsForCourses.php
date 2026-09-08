<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\Student;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileRequiredFormsForCourses implements ShouldQueue
{
    use Queueable;

    /** @param list<int> $courseIds */
    public function __construct(public readonly array $courseIds)
    {
        $this->afterCommit = true;
    }

    public function handle(ReconcileRequiredForms $reconcileRequiredForms): void
    {
        Student::query()
            ->whereHas('enrollments', fn ($query) => $query->whereIn('course_id', $this->courseIds))
            ->chunkById(100, fn ($students) => $students->each(
                fn (Student $student) => $reconcileRequiredForms->handle($student),
            ));
    }
}
