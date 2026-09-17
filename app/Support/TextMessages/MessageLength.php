<?php

declare(strict_types=1);

namespace App\Support\TextMessages;

use Illuminate\Validation\ValidationException;

final class MessageLength
{
    /** @return array{characters: int, parts: int, unicode: bool} */
    public static function measure(string $body): array
    {
        $basic = '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $extended = "\f^{}\\[~]|€";
        $units = 0;
        $unicode = false;

        foreach (mb_str_split($body) as $character) {
            if (str_contains($basic, $character)) {
                $units++;
            } elseif (str_contains($extended, $character)) {
                $units += 2;
            } else {
                $unicode = true;
                break;
            }
        }

        if ($unicode) {
            $units = (int) (mb_strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8'), '8bit') / 2);
        }

        $single = $unicode ? 70 : 160;
        $joined = $unicode ? 67 : 153;

        return [
            'characters' => mb_strlen($body),
            'parts' => $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $joined)),
            'unicode' => $unicode,
        ];
    }

    public static function validate(string $body): void
    {
        if (mb_trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Enter a text message.']);
        }

        if (self::measure($body)['parts'] > 6) {
            throw ValidationException::withMessages(['body' => 'Shorten the message to six SMS parts or fewer.']);
        }
    }
}
