<?php

declare(strict_types=1);

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'ionos_public' => [
            'driver' => 's3',
            'key' => env('IONOS_PUBLIC_ACCESS_KEY_ID'),
            'secret' => env('IONOS_PUBLIC_SECRET_ACCESS_KEY'),
            'region' => env('IONOS_REGION', 'us-central-1'),
            'bucket' => env('IONOS_PUBLIC_BUCKET'),
            'url' => env('IONOS_PUBLIC_URL'),
            'endpoint' => env('IONOS_ENDPOINT'),
            'use_path_style_endpoint' => env('IONOS_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'ionos_private' => [
            'driver' => 's3',
            'key' => env('IONOS_PRIVATE_ACCESS_KEY_ID'),
            'secret' => env('IONOS_PRIVATE_SECRET_ACCESS_KEY'),
            'region' => env('IONOS_REGION', 'us-central-1'),
            'bucket' => env('IONOS_PRIVATE_BUCKET'),
            'url' => env('IONOS_PRIVATE_URL'),
            'endpoint' => env('IONOS_ENDPOINT'),
            'use_path_style_endpoint' => env('IONOS_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
