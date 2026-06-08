<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Holiday;
use App\Services\HolidayConflictService;

final readonly class HolidayObserver
{
    public function __construct(private HolidayConflictService $holidayConflicts) {}

    public function saved(Holiday $holiday): void
    {
        $holiday->deletedConflictingEventsCount = $this->holidayConflicts->deleteConflictingEvents($holiday);
    }
}
