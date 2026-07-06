<?php

declare(strict_types=1);

namespace App\Forms\Contracts;

use App\Models\FormAssignment;

interface FormAssignmentUpdatePolicy
{
    public function supports(FormAssignment $assignment): bool;

    public function canUpdate(FormAssignment $assignment): bool;
}
