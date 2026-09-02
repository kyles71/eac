<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CourseTeacherAssignmentStrategy: string implements HasLabel
{
    case AllTeachers = 'all_teachers';
    case RotateTeachers = 'rotate_teachers';

    public function getLabel(): string
    {
        return match ($this) {
            self::AllTeachers => 'All teachers at every event',
            self::RotateTeachers => 'Rotate one teacher per event',
        };
    }
}
