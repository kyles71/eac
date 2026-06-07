<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\CourseForm;
use App\Models\Student;

final readonly class CourseFormObserver
{
    public function __construct(private ReconcileRequiredForms $reconcileRequiredForms) {}

    public function created(CourseForm $courseForm): void
    {
        $this->reconcileCourseStudents($courseForm);
    }

    public function deleted(CourseForm $courseForm): void
    {
        $this->reconcileCourseStudents($courseForm);
    }

    private function reconcileCourseStudents(CourseForm $courseForm): void
    {
        Student::query()
            ->whereHas('enrollments', fn ($query) => $query->where('course_id', $courseForm->course_id))
            ->each(fn (Student $student) => $this->reconcileRequiredForms->handle($student));
    }
}
