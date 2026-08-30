<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class() extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('reporting.not_running_maximum_enrollments', 2);
        $this->migrator->add('reporting.near_sold_out_maximum_remaining', 4);
        $this->migrator->add('reporting.capacity_metrics', []);
        $this->migrator->add('reporting.excluded_course_tag_slugs', []);
    }
};
