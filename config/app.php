<?php

declare(strict_types=1);

return [

    'name' => env('APP_NAME', 'EAC Plié Portal'),

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

    'seed_demo_data' => env('SEED_DEMO_DATA', false),

    'enrollment_unassign_cutoff_days' => (int) env('ENROLLMENT_UNASSIGN_CUTOFF_DAYS', 7),

    'substitute_request_reminder_hours' => (int) env('SUBSTITUTE_REQUEST_REMINDER_HOURS', 48),

    'file_uploads' => [
        'max_size_kilobytes' => 20 * 1024, // 20 MB
        'video_max_size_kilobytes' => 250 * 1024, // 250 MB
    ],
];
