<?php

namespace App\Providers\Filament;

use App\Filament\Shared\Pages\Profile\PersonalInfo;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Livewire\Livewire;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;

abstract class BasePanelProvider extends PanelProvider
{
    protected function applySharedConfig(Panel $panel): Panel
    {
        return $panel
            ->bootUsing(function (): void {
                Livewire::component('personal_info', PersonalInfo::class);
            })
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->discoverResources(in: app_path('Filament/Shared/Resources'), for: 'App\Filament\Shared\Resources')
            ->discoverPages(in: app_path('Filament/Shared/Pages'), for: 'App\Filament\Shared\Pages')
            ->discoverWidgets(in: app_path('Filament/Shared/Widgets'), for: 'App\Filament\Shared\Widgets')
            ->plugins([
                BreezyCore::make()
                    ->myProfile(userMenuLabel: 'My Profile')
                    ->myProfileComponents([
                        'personal_info' => PersonalInfo::class,
                    ])
                    ->enableTwoFactorAuthentication(),
                FilamentFullCalendarPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
