<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('TEXT_MESSAGES_ENABLED', false),
    'driver' => env('TEXT_MESSAGES_DRIVER', 'textmagic'),
    'sender' => env('TEXT_MESSAGES_SENDER', ''),
    'connect_timeout' => 5,
    'timeout' => 20,
    'drivers' => [
        'textmagic' => App\Services\TextMessages\TextmagicTransport::class,
    ],
];
