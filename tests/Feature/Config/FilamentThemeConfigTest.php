<?php

declare(strict_types=1);

it('includes shared Filament classes in both panel themes', function (): void {
    $globalTheme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($globalTheme)
        ->toContain("@source '../../../app/Filament/Shared/**/*';")
        ->toContain("@source '../../../resources/views/filament/shared/**/*';");
});
