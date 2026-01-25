<?php

namespace App\Providers\Filament;

use Filament\Panel;

class UserPanelProvider extends BasePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('user')
            ->path('user');

        $panel = $this->applySharedConfig($panel);

        return $panel
            ->viteTheme('resources/css/filament/user/theme.css')
            ->discoverResources(in: app_path('Filament/User/Resources'), for: 'App\Filament\User\Resources')
            ->discoverPages(in: app_path('Filament/User/Pages'), for: 'App\Filament\User\Pages')
            ->discoverWidgets(in: app_path('Filament/User/Widgets'), for: 'App\Filament\User\Widgets');
    }
}
