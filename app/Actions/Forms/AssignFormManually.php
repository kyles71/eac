<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kyle\FilamentFormBuilder\Actions\UpgradeFormAssignmentToVersion;
use LogicException;

final readonly class AssignFormManually
{
    public function __construct(private UpgradeFormAssignmentToVersion $upgradeAssignment) {}

    public function handle(Form $form, ?Student $student, ?User $respondent, User $assignedBy): FormAssignment
    {
        return DB::transaction(function () use ($assignedBy, $form, $respondent, $student): FormAssignment {
            $lockedForm = Form::query()->with('currentVersion')->lockForUpdate()->findOrFail($form->id);
            $version = $lockedForm->currentVersion;

            if ($version === null || ! $version->isActive()) {
                throw new LogicException('Only a currently active form can be assigned manually.');
            }

            if (in_array($lockedForm->key, ['student-waiver', 'showcase-participation'], true) && ! $student instanceof Student) {
                throw new LogicException('This form must be assigned to a student.');
            }

            if ($student instanceof Student) {
                $student->loadMissing('user');

                if (! $student->user instanceof User) {
                    throw new LogicException('The selected student must be linked to a user before assigning a form.');
                }

                $respondent = $student->user;
            }

            if (! $respondent instanceof User) {
                throw new LogicException('A respondent is required for a user-level form assignment.');
            }

            $assignment = $this->findAssignment($lockedForm, $student, $respondent, lock: true);

            if (! $assignment instanceof FormAssignment) {
                try {
                    $assignment = new FormAssignment([
                        'form_id' => $lockedForm->id,
                        'form_version_id' => $version->id,
                    ]);
                    $assignment->respondent()->associate($respondent);
                    $assignment->subject()->associate($student);
                    $assignment->is_manually_assigned = true;
                    $assignment->manuallyAssignedBy()->associate($assignedBy);
                    $assignment->manually_assigned_at = now();
                    $assignment->save();
                } catch (QueryException $exception) {
                    $assignment = $this->findAssignment($lockedForm, $student, $respondent, lock: true);

                    if (! $assignment instanceof FormAssignment) {
                        throw $exception;
                    }
                }
            }

            $assignment->respondent()->associate($respondent);
            $assignment->subject()->associate($student);
            $assignment->is_manually_assigned = true;
            $assignment->manuallyAssignedBy()->associate($assignedBy);
            $assignment->manually_assigned_at = now();
            $assignment->save();

            if ($assignment->form_version_id !== $version->id) {
                $this->upgradeAssignment->handle($assignment, $version);
            }

            return $assignment->refresh();
        });
    }

    private function findAssignment(Form $form, ?Student $student, User $respondent, bool $lock): ?FormAssignment
    {
        return FormAssignment::query()
            ->where('form_id', $form->id)
            ->when(
                $student instanceof Student,
                fn (Builder $query): Builder => $query->forSubject($student),
                fn (Builder $query): Builder => $query
                    ->whereNull('subject_type')
                    ->whereNull('subject_id')
                    ->forRespondent($respondent),
            )
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();
    }
}
