<?php

declare(strict_types=1);

namespace App\Enums;

enum StoreView: string
{
    case List = 'list';
    case Cards = 'cards';
}
