<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FormUsers\Schemas;

use App\Filament\Schemas\FormAssignmentInfolist;
use App\Models\FormAssignment;
use Filament\Schemas\Schema;

final class FormUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();

        if (! $record instanceof FormAssignment) {
            return $schema;
        }

        return FormAssignmentInfolist::configure($schema, $record);
    }
}
