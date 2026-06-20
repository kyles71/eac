<?php

declare(strict_types=1);

it('includes shared Filament classes in both panel themes', function (): void {
    $globalTheme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($globalTheme)
        ->toContain("@source '../../../app/Filament/Shared/**/*';")
        ->toContain("@source '../../../resources/views/filament/shared/**/*';");
});

it('keeps modal scrolling inside globally height-capped modal content', function (): void {
    $globalTheme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($globalTheme)
        ->toContain('.fi-modal > .fi-modal-window-ctn')
        ->toContain('overflow-y: hidden !important')
        ->toContain('max-height: 98vh !important')
        ->toContain('.fi-modal-window > .fi-modal-content')
        ->toContain('min-height: 0')
        ->toContain('overflow-y: auto')
        ->toContain('overscroll-behavior: contain')
        ->toContain('.fi-modal-window:has(> .fi-modal-header) > .fi-modal-content')
        ->toContain('padding-top: 2px')
        ->toContain('.fi-modal-window:has(> .fi-modal-header) > .fi-modal-header')
        ->toContain('padding-bottom: calc(var(--spacing, 0.25rem) * 6 - 2px)')
        ->toContain('.fi-modal-window-has-footer > .fi-modal-content')
        ->toContain('padding-bottom: 2px')
        ->toContain('.fi-modal-window-has-footer > .fi-modal-footer')
        ->toContain('padding-top: calc(var(--spacing, 0.25rem) * 6 - 2px)');
});
