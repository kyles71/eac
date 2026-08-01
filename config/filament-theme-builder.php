<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Builder Route Slug
    |--------------------------------------------------------------------------
    |
    | The plugin may override this per panel through ThemeBuilderPlugin::builderSlug().
    |
    */

    'builder_slug' => 'theme-builder',

    /*
    |--------------------------------------------------------------------------
    | Builder Sidebar
    |--------------------------------------------------------------------------
    |
    | The builder needs a wide canvas. Temporarily collapse the Filament sidebar
    | while the builder is active, then restore the user's previous state.
    |
    */

    'hide_sidebar_by_default' => false,

    'collapse_sidebar_by_default' => true,

    /*
    |--------------------------------------------------------------------------
    | Builder Navigation
    |--------------------------------------------------------------------------
    |
    | These values configure how the builder page appears in Filament's
    | navigation. Per-panel plugin methods override these defaults.
    |
    */

    'navigation_icon' => 'heroicon-o-swatch',

    'active_navigation_icon' => null,

    'navigation_group' => null,

    'navigation_label' => 'Theme Builder',

    'navigation_sort' => null,

    /*
    |--------------------------------------------------------------------------
    | Managed Panels
    |--------------------------------------------------------------------------
    |
    | Configure panel IDs and optional labels that the builder can manage.
    | Leave empty to allow discovery of registered Filament panels.
    |
    | Example:
    | [
    |     'admin' => 'Admin',
    |     'app' => 'Application',
    | ]
    |
    */

    'managed_panels' => [],
];
