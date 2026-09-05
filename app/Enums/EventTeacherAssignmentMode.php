<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventTeacherAssignmentMode: string implements HasLabel
{
    case CourseDefaults = 'course_defaults';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return match ($this) {
            self::CourseDefaults => 'Use course teacher defaults',
            self::Custom => 'Choose teachers for this event',
        };
    }
}
