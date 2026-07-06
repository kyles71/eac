<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\Student;
use Illuminate\Support\Collection;
use LogicException;

final readonly class ReconcileRequiredForms
{
    public function __construct(private UpgradeFormAssignmentToVersion $upgradeAssignment) {}

    public function handle(Student $student): void
    {
        $requiredForms = $this->requiredForms($student);

        if ($student->user_id === null) {
            $this->removeStalePendingAssignments($student, []);

            return;
        }

        $requiredForms->each(fn (Form $form): FormAssignment => $this->ensureAssignment($student, $form));
        $this->removeStalePendingAssignments($student, $requiredForms->pluck('id')->all());
    }

    /**
     * @return Collection<int, Form>
     */
    private function requiredForms(Student $student): Collection
    {
        return Form::query()
            ->isActive()
            ->with('currentVersion')
            ->whereHas('courses.enrollments', fn ($query) => $query->where('enrollments.student_id', $student->id))
            ->get();
    }

    private function ensureAssignment(Student $student, Form $form): FormAssignment
    {
        $version = $form->currentVersion;

        if ($version === null) {
            throw new LogicException('Required forms must have a published current version before assignment.');
        }

        $assignment = FormAssignment::query()
            ->where('form_id', $form->id)
            ->where('subject_type', $student->getMorphClass())
            ->where('subject_id', $student->id)
            ->first();

        if ($assignment !== null) {
            if (! $assignment->isCompleted() && $assignment->respondent_id !== $student->user_id) {
                $assignment->respondent()->associate($student->user);
                $assignment->save();
            }

            if (! $assignment->isCompleted() && $assignment->form_version_id !== $version->id) {
                $this->upgradeAssignment->handle($assignment, $version);
            }

            return $assignment;
        }

        $assignment = new FormAssignment([
            'form_id' => $form->id,
            'form_version_id' => $version->id,
        ]);
        $assignment->respondent()->associate($student->user);
        $assignment->subject()->associate($student);
        $assignment->save();

        return $assignment;
    }

    /**
     * @param  array<int, int>  $requiredFormIds
     */
    private function removeStalePendingAssignments(Student $student, array $requiredFormIds): void
    {
        $student->formAssignments()
            ->pending()
            ->when(
                $requiredFormIds !== [],
                fn ($query) => $query->whereNotIn('form_id', $requiredFormIds),
            )
            ->get()
            ->each(function (FormAssignment $assignment): void {
                $assignment->delete();
            });
    }
}
