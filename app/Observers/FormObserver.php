<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\Form;
use App\Models\Student;

final readonly class FormObserver
{
    public function __construct(private ReconcileRequiredForms $reconcileRequiredForms) {}

    public function updated(Form $form): void
    {
        if (! $form->wasChanged('valid_until')) {
            return;
        }

        Student::query()
            ->whereHas('enrollments.course.forms', fn ($query) => $query->whereKey($form->id))
            ->each(fn (Student $student) => $this->reconcileRequiredForms->handle($student));
    }
}
