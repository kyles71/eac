<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FormTypes;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class UserAttention
{
    public const string UPDATED_EVENT = 'user-attention-updated';

    public function openEnrollmentCount(User $user): int
    {
        return $user->enrollments()->whereNull('student_id')->count();
    }

    /**
     * @return Collection<int, FormUser>
     */
    public function pendingForms(User $user): Collection
    {
        return FormUser::query()
            ->with(['form', 'student'])
            ->where('user_id', $user->id)
            ->pending()
            ->whereHas('form', fn (Builder $query): Builder => Form::applyActiveConstraint($query))
            ->get();
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return Collection<int, FormUser>
     */
    public function pendingFormsForStudents(User $user, array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return $this->pendingForms($user)
            ->whereIn('student_id', $studentIds)
            ->values();
    }

    /**
     * @param  Collection<int, FormUser>  $pendingForms
     * @return Collection<int, FormUser>
     */
    public function assignmentsForFormType(Collection $pendingForms, FormTypes $formType): Collection
    {
        return $pendingForms
            ->filter(fn (FormUser $formUser): bool => $formUser->form?->form_type === $formType)
            ->values();
    }

    /**
     * @param  Collection<int, FormUser>  $pendingForms
     * @return Collection<int, FormUser>
     */
    public function genericForms(Collection $pendingForms): Collection
    {
        return $pendingForms
            ->reject(fn (FormUser $formUser): bool => $formUser->form?->form_type->getBannerView() !== null)
            ->values();
    }
}
