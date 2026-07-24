<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kyle\FilamentFormBuilder\Actions\UpgradeFormAssignmentToVersion;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use LogicException;

final readonly class ReconcileRequiredForms
{
    public function __construct(private UpgradeFormAssignmentToVersion $upgradeAssignment) {}

    public function handle(Student $student): void
    {
        if ($student->user_id !== null) {
            $this->synchronizeRespondents($student);
        }

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
            ->whereHas('courses', fn (Builder $query): Builder => $query
                ->whereHas('enrollments', fn (Builder $query): Builder => $query
                    ->where('enrollments.student_id', $student->id))
                ->where(fn (Builder $query): Builder => $query
                    ->whereDoesntHave('events', fn (Builder $query): Builder => $query->whereNull('cancelled_at'))
                    ->orWhereHas('events', fn (Builder $query): Builder => $query
                        ->whereNull('cancelled_at')
                        ->where(function (Builder $query): void {
                            $query
                                ->where('end_time', '>=', now())
                                ->orWhere(function (Builder $query): void {
                                    $query->whereNull('end_time')->where('start_time', '>=', now());
                                });
                        }))))
            ->get();
    }

    private function ensureAssignment(Student $student, Form $form): FormAssignment
    {
        return DB::transaction(function () use ($form, $student): FormAssignment {
            $lockedForm = Form::query()->with('currentVersion')->lockForUpdate()->findOrFail($form->id);
            $version = $lockedForm->currentVersion;

            if ($version === null) {
                throw new LogicException('Required forms must have a published current version before assignment.');
            }

            $query = FormAssignment::query()
                ->where('form_id', $lockedForm->id)
                ->where('subject_type', $student->getMorphClass())
                ->where('subject_id', $student->id);
            $assignment = (clone $query)->lockForUpdate()->first();

            if (! $assignment instanceof FormAssignment) {
                try {
                    $assignment = new FormAssignment([
                        'form_id' => $lockedForm->id,
                        'form_version_id' => $version->id,
                    ]);
                    $assignment->respondent()->associate($student->user);
                    $assignment->subject()->associate($student);
                    $assignment->save();
                } catch (QueryException $exception) {
                    $assignment = (clone $query)->lockForUpdate()->first();

                    if (! $assignment instanceof FormAssignment) {
                        throw $exception;
                    }
                }
            }

            if ($assignment->respondent_id !== $student->user_id) {
                $assignment->respondent()->associate($student->user);
                $assignment->save();
            }

            if (
                $assignment->form_version_id !== $version->id
                && ($version->require_completed_again || ! $assignment->isCompleted())
            ) {
                $this->upgradeAssignment->handle($assignment, $version);
            }

            return $assignment->refresh();
        });
    }

    /**
     * @param  array<int, int>  $requiredFormIds
     */
    private function removeStalePendingAssignments(Student $student, array $requiredFormIds): void
    {
        $student->formAssignments()
            ->where('is_manually_assigned', false)
            ->when(
                $requiredFormIds !== [],
                fn ($query) => $query->whereNotIn('form_id', $requiredFormIds),
            )
            ->with('responses')
            ->get()
            ->each(function (FormAssignment $assignment) use ($student): void {
                $latestSubmitted = $assignment->responses
                    ->where('status', FormResponseStatus::Submitted)
                    ->sortBy([
                        ['submitted_at', 'desc'],
                        ['id', 'desc'],
                    ])
                    ->first();

                if ($latestSubmitted === null) {
                    $assignment->delete();

                    return;
                }

                $assignment->responses()
                    ->where('status', FormResponseStatus::Draft)
                    ->delete();

                if (
                    $student->user_id !== null
                    && $assignment->form_version_id !== $latestSubmitted->form_version_id
                ) {
                    $assignment->form_version_id = $latestSubmitted->form_version_id;
                    $assignment->save();
                }
            });
    }

    private function synchronizeRespondents(Student $student): void
    {
        $student->loadMissing('user');

        if ($student->user === null) {
            return;
        }

        $student->formAssignments()
            ->where(function (Builder $query) use ($student): void {
                $query
                    ->where('respondent_type', '!=', $student->user->getMorphClass())
                    ->orWhere('respondent_id', '!=', $student->user_id);
            })
            ->each(function (FormAssignment $assignment) use ($student): void {
                $assignment->respondent()->associate($student->user);
                $assignment->save();
            });
    }
}
