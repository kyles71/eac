<?php

declare(strict_types=1);

namespace App\Contracts;

interface HasCapacity
{
    /**
     * Get the number of available spots remaining.
     */
    public function getAvailableCapacity(): int;
}
