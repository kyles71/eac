<?php

declare(strict_types=1);

namespace App\Support;

final class BoardAttachments
{
    public const int MAX_SIZE_KILOBYTES = 10 * 1024;

    /** @return list<string> */
    public static function acceptedFileTypes(): array
    {
        return [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
            'text/csv',
            'text/plain',
        ];
    }
}
