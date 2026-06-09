<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('dashboard_appearance.messages_bullet_image', null);
        $this->migrator->add('dashboard_appearance.quick_links_bullet_image', null);
    }
};
