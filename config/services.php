<?php

declare(strict_types=1);

return [

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'textmagic' => [
        'username' => env('TEXTMAGIC_USERNAME'),
        'api_key' => env('TEXTMAGIC_API_KEY'),
    ],

    'github_updates' => [
        'repository' => env('GITHUB_UPDATES_REPOSITORY', 'kyles71/eac'),
        'token' => env('GITHUB_UPDATES_TOKEN'),
        'cache_ttl' => (int) env('GITHUB_UPDATES_CACHE_TTL', 300),
        'release_limit' => (int) env('GITHUB_UPDATES_RELEASE_LIMIT', 20),
    ],

];
