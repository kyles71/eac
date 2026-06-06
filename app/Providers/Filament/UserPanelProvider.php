<?php

namespace App\Providers\Filament;

use App\Filament\User\Pages\Auth\Register;
use App\Filament\User\Pages\Dashboard;
use App\Http\Middleware\UserBanners;
use Filament\Panel;

class UserPanelProvider extends BasePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('user')
            ->path('dancefam');

        $panel = $this->applySharedConfig($panel);

        return $panel
            ->brandName('EAC Dancer')
            ->viteTheme('resources/css/filament/user/theme.css')
            ->registration(Register::class)
            ->middleware([
                UserBanners::class,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->discoverResources(in: app_path('Filament/User/Resources'), for: 'App\Filament\User\Resources')
            ->discoverPages(in: app_path('Filament/User/Pages'), for: 'App\Filament\User\Pages')
            ->discoverWidgets(in: app_path('Filament/User/Widgets'), for: 'App\Filament\User\Widgets');
    }
}
