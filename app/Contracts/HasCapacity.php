<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface HasCapacity
{
    /**
     * Get the number of available spots remaining.
     */
    public function getAvailableCapacity(?User $user = null): int;
}
