<?php

declare(strict_types=1);

use App\Support\MediaDisks;

it('defaults local media disks for development', function () {
    expect(MediaDisks::public())->toBe('public')
        ->and(MediaDisks::private())->toBe('local');
});

it('defines ionos object storage disks without contacting ionos', function () {
    expect(config('filesystems.disks.ionos_public'))->toMatchArray([
        'driver' => 's3',
        'region' => 'us-central-1',
        'visibility' => 'public',
    ])
        ->and(config('filesystems.disks.ionos_private'))->toMatchArray([
            'driver' => 's3',
            'region' => 'us-central-1',
            'visibility' => 'private',
        ])
        ->and(config('filesystems.disks.ionos_public'))->toHaveKey('endpoint')
        ->and(config('filesystems.disks.ionos_private'))->toHaveKey('endpoint');
});

it('can switch media disks to ionos for deployed environments', function () {
    config([
        'media.public_disk' => 'ionos_public',
        'media.private_disk' => 'ionos_private',
    ]);

    expect(MediaDisks::public())->toBe('ionos_public')
        ->and(MediaDisks::private())->toBe('ionos_private');
});
