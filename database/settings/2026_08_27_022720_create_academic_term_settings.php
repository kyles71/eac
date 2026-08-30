<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('academic_terms.winter_spring_starts_on', '01-01');
        $this->migrator->add('academic_terms.summer_starts_on', '06-01');
        $this->migrator->add('academic_terms.fall_starts_on', '09-01');
    }
};
