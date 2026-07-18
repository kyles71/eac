<?php

declare(strict_types=1);

namespace App\Listeners\Forms;

use App\Actions\Forms\ReconcileRequiredForms;
use App\Models\Student;
use Kyle\FilamentFormBuilder\Events\FormVersionActivated;

final readonly class ReconcileRequiredFormsForPublishedVersion
{
    public function __construct(private ReconcileRequiredForms $reconcileRequiredForms) {}

    public function handle(FormVersionActivated $event): void
    {
        Student::query()
            ->whereHas('enrollments.course.forms', fn ($query) => $query->whereKey($event->version->form_id))
            ->each(function (Student $student): void {
                $this->reconcileRequiredForms->handle($student);
            });
    }
}
