<?php

declare(strict_types=1);

use Filament\Support\Facades\FilamentAsset;

it('loads the theme builder preview controller through async Alpine assets', function (): void {
    $componentSrc = FilamentAsset::getAlpineComponentSrc(
        'theme-builder',
        'kyle/filament-theme-builder',
    );

    $scriptIds = collect(FilamentAsset::getScripts(['kyle/filament-theme-builder']))
        ->map(fn ($script): string => $script->getId());

    expect($componentSrc)
        ->toContain('/js/kyle/filament-theme-builder/components/theme-builder.js')
        ->and($scriptIds)
        ->not->toContain('theme-builder')
        ->toContain('preview-bridge');
});

it('uses x-load for the theme builder preview controller', function (): void {
    $view = file_get_contents(base_path('vendor/kyle/filament-theme-builder/resources/views/pages/theme-builder.blade.php'));

    expect($view)
        ->toContain('x-load')
        ->toContain('getAlpineComponentSrc(\'theme-builder\'')
        ->not->toContain('getScriptSrc(\'theme-builder\'');
});
