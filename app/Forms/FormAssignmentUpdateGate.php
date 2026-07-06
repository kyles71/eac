<?php

declare(strict_types=1);

namespace App\Forms;

use App\Forms\Contracts\FormAssignmentUpdatePolicy;
use App\Models\FormAssignment;

final readonly class FormAssignmentUpdateGate
{
    public function canUpdate(FormAssignment $assignment): bool
    {
        foreach ($this->policies() as $policy) {
            if ($policy->supports($assignment)) {
                return $policy->canUpdate($assignment);
            }
        }

        return true;
    }

    /**
     * @return \Illuminate\Support\Collection<int, FormAssignmentUpdatePolicy>
     */
    private function policies(): \Illuminate\Support\Collection
    {
        return collect(config('forms.update_policies', []))
            ->map(fn (string $policy): FormAssignmentUpdatePolicy => app($policy));
    }
}
