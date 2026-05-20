<?php

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
    | The builder needs a wide canvas. By default, the package adds a body class
    | that hides the Filament sidebar while the builder page is active.
    |
    */

    'hide_sidebar_by_default' => true,

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
