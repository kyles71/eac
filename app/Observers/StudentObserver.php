<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

final readonly class StudentObserver
{
    public function __construct(private ReconcileRequiredForms $reconcileRequiredForms) {}

    public function updated(Student $student): void
    {
        if ($student->wasChanged('user_id')) {
            $this->reconcileRequiredForms->handle($student);
        }
    }

    public function deleting(Student $student): void
    {
        if (! $student->canBeDeleted()) {
            throw ValidationException::withMessages([
                'student' => 'Students enrolled in courses or attending events cannot be deleted.',
            ]);
        }
    }
}
