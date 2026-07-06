<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\FormUsers\Schemas;

use App\Filament\Schemas\FormAssignmentInfolist;
use App\Models\FormAssignment;
use Filament\Schemas\Schema;

final class FormUserInfolist
{
    public static function configure(Schema $schema, FormAssignment $assignment): Schema
    {
        return FormAssignmentInfolist::configure($schema, $assignment);
    }
}
