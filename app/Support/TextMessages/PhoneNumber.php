<?php

declare(strict_types=1);

namespace App\Support\TextMessages;

final class PhoneNumber
{
    public static function normalize(string $phone): ?string
    {
        $phone = mb_trim($phone);

        if (! preg_match('/^\+?[0-9() .\-]+$/D', $phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (mb_strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = mb_substr($digits, 1);
        } elseif (str_starts_with($phone, '+')) {
            return null;
        }

        return preg_match('/^[2-9][0-9]{2}[2-9][0-9]{6}$/D', $digits)
            ? '+1'.$digits
            : null;
    }
}
