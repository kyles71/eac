<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Dashboard;
use App\Support\Filament\AdminNavigation;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Enums\Platform;
use Filament\Support\Icons\Heroicon;
use Kyle\FilamentMailManager\FilamentMailManagerPlugin;
use Kyle\FilamentThemeBuilder\ThemeBuilderPlugin;

final class AdminPanelProvider extends BasePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin');

        $panel = $this->applySharedConfig($panel);

        return $panel
            ->brandName('EAC Admin')
            ->strictAuthorization()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                NavigationGroup::make(AdminNavigation::PeopleAndAccess)
                    ->icon(Heroicon::OutlinedUsers),
                NavigationGroup::make(AdminNavigation::ClassesAndSchedule)
                    ->icon(Heroicon::OutlinedCalendarDays),
                NavigationGroup::make(AdminNavigation::Storefront)
                    ->icon(Heroicon::OutlinedShoppingBag),
                NavigationGroup::make(AdminNavigation::SalesAndBilling)
                    ->icon(Heroicon::OutlinedCreditCard),
                NavigationGroup::make(AdminNavigation::Competition)
                    ->icon(Heroicon::OutlinedSparkles),
                NavigationGroup::make(AdminNavigation::Email)
                    ->icon(Heroicon::OutlinedEnvelope),
                NavigationGroup::make(AdminNavigation::Settings)
                    ->icon(Heroicon::OutlinedCog6Tooth),
            ])
            ->pages([
                Dashboard::class,
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup(AdminNavigation::PeopleAndAccess)
                    ->gridColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 4,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                    ]),
                ThemeBuilderPlugin::make()
                    ->authorizeUsing('Manage:ThemeBuilder'),
                FilamentMailManagerPlugin::make()
                    ->emailTypeEditActionSlideOver()
                    ->enableSentEmails(false)
                    ->navigationGroup(AdminNavigation::Email),
            ])
            ->globalSearchFieldSuffix(fn (): ?string => match (Platform::detect()) {
                Platform::Windows, Platform::Linux => 'CTRL + K',
                Platform::Mac => '⌘ + K',
                default => null,
            });
    }
}
