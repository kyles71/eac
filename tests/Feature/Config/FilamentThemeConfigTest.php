<?php

declare(strict_types=1);

it('includes shared Filament classes in both panel themes', function (): void {
    $globalTheme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($globalTheme)
        ->toContain("@source '../../../app/Filament/Shared/**/*';")
        ->toContain("@source '../../../resources/views/filament/shared/**/*';");
});

it('includes form builder package classes in the admin theme', function (): void {
    $adminTheme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect($adminTheme)
        ->toContain("@source '../../../../vendor/kyle/filament-form-builder/src/**/*.php';")
        ->toContain("@source '../../../../vendor/kyle/filament-form-builder/resources/**/*.blade.php';");
});

it('pins the user panel topbar on mobile viewports', function (): void {
    $userTheme = file_get_contents(resource_path('css/filament/user/theme.css'));

    expect($userTheme)
        ->toContain('@media (max-width: 1023px)')
        ->toContain('--eac-user-mobile-topbar-offset')
        ->toContain('.fi-panel-user.fi-body-has-topbar .fi-topbar-ctn')
        ->toContain('position: fixed')
        ->toContain('inset-block-start: env(safe-area-inset-top, 0px)')
        ->toContain('.fi-panel-user.fi-body-has-topbar .fi-layout')
        ->toContain('padding-block-start: var(--eac-user-mobile-topbar-offset)');
});
