<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\Student;

final readonly class StudentObserver
{
    public function __construct(private ReconcileRequiredForms $reconcileRequiredForms) {}

    public function updated(Student $student): void
    {
        if ($student->wasChanged('user_id')) {
            $this->reconcileRequiredForms->handle($student);
        }
    }
}
