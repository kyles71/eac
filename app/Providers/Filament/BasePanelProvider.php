<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Shared\Pages\Auth\Login;
use App\Filament\Shared\Pages\Auth\ResetPassword;
use App\Filament\Shared\Pages\Profile\Profile;
use App\Http\Middleware\ManagedBanners;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;

abstract class BasePanelProvider extends PanelProvider
{
    protected function applySharedConfig(Panel $panel): Panel
    {
        return $panel
            ->login(Login::class)
            ->passwordReset(resetAction: ResetPassword::class)
            ->profile(Profile::class, isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ])
            ->spa()
            ->darkMode(false)
            ->unsavedChangesAlerts()
            ->databaseTransactions()
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->pages([

            ])
            ->widgets([

            ])
            ->discoverResources(in: app_path('Filament/Shared/Resources'), for: 'App\Filament\Shared\Resources')
            ->discoverPages(in: app_path('Filament/Shared/Pages'), for: 'App\Filament\Shared\Pages')
            ->discoverWidgets(in: app_path('Filament/Shared/Widgets'), for: 'App\Filament\Shared\Widgets')
            ->plugins([
                FilamentFullCalendarPlugin::make()->timezone(config('app.display_timezone')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                ManagedBanners::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->persistentMiddleware([
                ManagedBanners::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
