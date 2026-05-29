<?php

declare(strict_types=1);

namespace App\Contracts;

interface ProvidesStorefrontDetails
{
    /**
     * @return array<string, string>
     */
    public function storefrontDetails(): array;
}
