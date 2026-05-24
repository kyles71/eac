<?php

declare(strict_types=1);

namespace App\Support;

final class MediaDisks
{
    public static function public(): string
    {
        return (string) config('media.public_disk', 'public');
    }

    public static function private(): string
    {
        return (string) config('media.private_disk', 'local');
    }
}
