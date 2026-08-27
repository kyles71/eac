<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class AcademicTermSettings extends Settings
{
    public string $winter_spring_starts_on;

    public string $summer_starts_on;

    public string $fall_starts_on;

    public static function group(): string
    {
        return 'academic_terms';
    }
}
