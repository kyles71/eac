<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum HolidayEventScope: string implements HasLabel
{
    case AllEvents = 'AllEvents';
    case CourseClassesOnly = 'CourseClassesOnly';

    public function getLabel(): string
    {
        return match ($this) {
            self::AllEvents => 'All Events',
            self::CourseClassesOnly => 'Course Classes Only',
        };
    }
}
