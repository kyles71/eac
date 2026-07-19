<?php

declare(strict_types=1);

it('includes shared Filament classes in both panel themes', function (): void {
    $globalTheme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($globalTheme)
        ->toContain("@source '../../../app/Filament/Shared/**/*';")
        ->toContain("@source '../../../resources/views/filament/shared/**/*';")
        ->toContain("[data-managed-banners-location='panels::topbar.before']")
        ->toContain('inset-block-start: var(--eac-managed-banner-height, 0px)');
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
