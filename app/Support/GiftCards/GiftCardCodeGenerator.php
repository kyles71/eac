<?php

declare(strict_types=1);

namespace App\Support\GiftCards;

use App\Models\GiftCard;
use Illuminate\Support\Str;

final class GiftCardCodeGenerator
{
    public function generate(int $length = 16): string
    {
        do {
            $code = mb_strtoupper(Str::random($length));
        } while (GiftCard::query()->where('code', $code)->exists());

        return $code;
    }
}
