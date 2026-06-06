<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Dates are stored in UTC and displayed in the local business timezone.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'America/Detroit'),

    /*
    |--------------------------------------------------------------------------
    | Default User Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines the default user credentials that will be
    | used in local development environments. It is particularly useful
    | for quickly logging into the application without needing
    | to create a user manually.
    |
    */

    'default_user' => [
        'first_name' => env('DEFAULT_USER_FIRST_NAME', 'Admin'),
        'last_name' => env('DEFAULT_USER_LAST_NAME', 'User'),
        'email' => env('DEFAULT_USER_EMAIL', 'admin@example.com'),
        'password' => env('DEFAULT_USER_PASSWORD', 'password'),
    ],

    'enrollment_unassign_cutoff_days' => (int) env('ENROLLMENT_UNASSIGN_CUTOFF_DAYS', 7),

    'file_uploads' => [
        'max_size_kilobytes' => 20 * 1024, // 20 MB
        'video_max_size_kilobytes' => 250 * 1024, // 250 MB
    ],
];
