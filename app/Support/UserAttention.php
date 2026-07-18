<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FormAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class UserAttention
{
    public const string UPDATED_EVENT = 'user-attention-updated';

    public function openEnrollmentCount(User $user): int
    {
        return $user->enrollments()->whereNull('student_id')->count();
    }

    /**
     * @return Collection<int, FormAssignment>
     */
    public function pendingForms(User $user): Collection
    {
        return FormAssignment::query()
            ->with(['form', 'subject'])
            ->forRespondent($user)
            ->pending()
            ->formIsActive()
            ->get();
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return Collection<int, FormAssignment>
     */
    public function pendingFormsForStudents(User $user, array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return $this->pendingForms($user)
            ->filter(fn (FormAssignment $assignment): bool => $assignment->subject_type === (new Student())->getMorphClass()
                && in_array($assignment->subject_id, $studentIds, true))
            ->values();
    }

    /**
     * @param  Collection<int, FormAssignment>  $pendingForms
     * @return Collection<int, FormAssignment>
     */
    public function assignmentsForKey(Collection $pendingForms, string $formKey): Collection
    {
        return $pendingForms
            ->filter(fn (FormAssignment $assignment): bool => $assignment->form->key === $formKey)
            ->values();
    }

    /**
     * @param  Collection<int, FormAssignment>  $pendingForms
     * @return Collection<int, FormAssignment>
     */
    public function genericForms(Collection $pendingForms): Collection
    {
        return $pendingForms
            ->reject(fn (FormAssignment $assignment): bool => $assignment->form->key === 'student-waiver')
            ->values();
    }
}
