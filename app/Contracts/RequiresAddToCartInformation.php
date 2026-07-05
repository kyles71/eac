<?php

declare(strict_types=1);

namespace App\Contracts;

interface RequiresAddToCartInformation
{
    public function requiresAddToCartInformation(): bool;
}
