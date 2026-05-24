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

];
