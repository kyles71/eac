<?php

declare(strict_types=1);

namespace App\Listeners\Forms;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Events\Forms\FormVersionPublished;
use App\Models\Student;

final readonly class ReconcileRequiredFormsForPublishedVersion
{
    public function __construct(private ReconcileRequiredForms $reconcileRequiredForms) {}

    public function handle(FormVersionPublished $event): void
    {
        Student::query()
            ->whereHas('enrollments.course.forms', fn ($query) => $query->whereKey($event->version->form_id))
            ->each(function (Student $student): void {
                $this->reconcileRequiredForms->handle($student);
            });
    }
}
