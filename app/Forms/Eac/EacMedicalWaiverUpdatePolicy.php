<?php

declare(strict_types=1);

namespace App\Forms\Eac;

use App\Enums\FormPurpose;
use App\Forms\Contracts\FormAssignmentUpdatePolicy;
use App\Models\FormAssignment;
use App\Models\Student;

final readonly class EacMedicalWaiverUpdatePolicy implements FormAssignmentUpdatePolicy
{
    public function supports(FormAssignment $assignment): bool
    {
        return $assignment->form->purpose === FormPurpose::MedicalWaiver;
    }

    public function canUpdate(FormAssignment $assignment): bool
    {
        $assignment->loadMissing(['form', 'subject']);

        return $assignment->subject instanceof Student
            && ($assignment->subject->latestValidCompletedMedicalWaiver()?->is($assignment) ?? false);
    }
}
