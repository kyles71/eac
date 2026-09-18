<?php

declare(strict_types=1);

use App\Providers\Filament\UserPanelProvider;
use Filament\Panel;
use Illuminate\Support\Facades\Vite;

it('configures the user panel without resolving Vite assets', function (): void {
    Vite::shouldReceive('asset')->never();

    $panel = (new UserPanelProvider(app()))->panel(Panel::make());

    expect($panel->getBrandName())->toBe('EAC Plié Portal')
        ->and(file_get_contents(base_path('.env.example')))->toContain('APP_NAME="EAC Plié Portal"');
});

it('includes shared Filament classes in both panel themes', function (): void {
    $globalTheme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($globalTheme)
        ->toContain("@source '../../../app/Filament/Shared/**/*';")
        ->toContain("@source '../../../resources/views/filament/shared/**/*';")
        ->toContain("[data-managed-banners-location='panels::topbar.before']")
        ->toContain('inset-block-start: var(--eac-managed-banner-height, 0px)');
});

it('includes form builder package classes in the admin theme', function (): void {
    $adminTheme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($adminTheme)
        ->toContain("@source '../../../../vendor/kyle/filament-form-builder/src/**/*.php';")
        ->toContain("@source '../../../../vendor/kyle/filament-form-builder/resources/**/*.blade.php';")
        ->toContain('.fi-panel-admin.fi-form-builder-designer-page.fi-body-has-topbar .fi-layout')
        ->toContain('height: calc(100dvh - 4rem - var(--eac-managed-banner-height, 0px))')
        ->toContain('.fi-panel-admin.fi-form-builder-designer-page .fi-main [data-managed-banners-location]');
});

it('pins the user panel topbar on mobile viewports', function (): void {
    $userTheme = file_get_contents(resource_path('css/filament/user/theme.css'));

    expect($userTheme)
        ->toContain('@media (max-width: 1023px)')
        ->toContain('--eac-user-mobile-topbar-offset')
        ->toContain('.fi-panel-user.fi-body-has-topbar .fi-topbar-ctn')
        ->toContain('position: fixed')
        ->toContain("[data-managed-banners-location='panels::topbar.before']")
        ->toContain('inset-block-start: calc(env(safe-area-inset-top, 0px) + var(--eac-managed-banner-height, 0px))')
        ->toContain('.fi-panel-user.fi-body-has-topbar .fi-layout')
        ->toContain('padding-block-start: var(--eac-user-mobile-topbar-offset)');
});
