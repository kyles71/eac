<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class ReportingSettings extends Settings
{
    public int $not_running_maximum_enrollments;

    public int $near_sold_out_maximum_remaining;

    /** @phpstan-var list<array<string, mixed>> */
    public array $capacity_metrics;

    /** @var list<string> */
    public array $excluded_course_tag_slugs;

    public static function group(): string
    {
        return 'reporting';
    }
}
