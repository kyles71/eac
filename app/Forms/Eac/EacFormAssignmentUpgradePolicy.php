<?php

declare(strict_types=1);

namespace App\Forms\Eac;

use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Kyle\FilamentFormBuilder\Contracts\FormAssignmentUpgradePolicy;
use Kyle\FilamentFormBuilder\Models\FormAssignment;
use Kyle\FilamentFormBuilder\Models\FormVersion;

final readonly class EacFormAssignmentUpgradePolicy implements FormAssignmentUpgradePolicy
{
    public function canUpgrade(FormAssignment $assignment, FormVersion $version): bool
    {
        $assignment->loadMissing('subject');

        if (! $assignment->subject instanceof Student) {
            return false;
        }

        $now = now();

        return Enrollment::query()
            ->where('student_id', $assignment->subject->getKey())
            ->whereHas('course.forms', fn (Builder $query): Builder => $query->whereKey($version->form_id))
            ->whereHas('course', fn (Builder $query): Builder => $query
                ->whereDoesntHave('events')
                ->orWhereHas('events', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($now): void {
                    $query
                        ->where('end_time', '>=', $now)
                        ->orWhere(function (Builder $query) use ($now): void {
                            $query->whereNull('end_time')->where('start_time', '>=', $now);
                        });
                })))
            ->exists();
    }
}
