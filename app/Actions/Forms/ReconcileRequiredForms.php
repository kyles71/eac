<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class ReconcileRequiredForms
{
    public function handle(Student $student): void
    {
        $requiredForms = $this->requiredForms($student);

        if ($student->user_id === null) {
            $this->removeStalePendingAssignments($student, []);

            return;
        }

        $requiredForms->each(fn (Form $form): FormUser => $this->ensureAssignment($student, $form));
        $this->removeStalePendingAssignments($student, $requiredForms->pluck('id')->all());
    }

    /**
     * @return Collection<int, Form>
     */
    private function requiredForms(Student $student): Collection
    {
        return Form::query()
            ->isActive()
            ->whereHas('courses.enrollments', fn ($query) => $query->where('enrollments.student_id', $student->id))
            ->get();
    }

    private function ensureAssignment(Student $student, Form $form): FormUser
    {
        $assignment = FormUser::query()
            ->where('form_id', $form->id)
            ->where('student_id', $student->id)
            ->first();

        if ($assignment !== null) {
            if (! $assignment->isCompleted() && $assignment->user_id !== $student->user_id) {
                $assignment->update(['user_id' => $student->user_id]);
            }

            return $assignment;
        }

        /** @var class-string<Model> $responseableClass */
        $responseableClass = $form->form_type->value;
        /** @var Model $responseable */
        $responseable = new $responseableClass();
        $responseable->save();

        $assignment = new FormUser([
            'form_id' => $form->id,
            'user_id' => $student->user_id,
            'student_id' => $student->id,
        ]);
        $assignment->responseable()->associate($responseable);
        $assignment->save();

        return $assignment;
    }

    /**
     * @param  array<int, int>  $requiredFormIds
     */
    private function removeStalePendingAssignments(Student $student, array $requiredFormIds): void
    {
        $student->forms()
            ->pending()
            ->when(
                $requiredFormIds !== [],
                fn ($query) => $query->whereNotIn('form_id', $requiredFormIds),
            )
            ->with('responseable')
            ->get()
            ->each(function (FormUser $assignment): void {
                $responseable = $assignment->responseable;

                $assignment->delete();
                $responseable?->delete();
            });
    }
}
