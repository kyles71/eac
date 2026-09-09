<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

final class ScheduleRecurrenceLimitExceededException extends DomainException
{
    public function __construct(int $maximumOccurrences)
    {
        parent::__construct("Recurring schedules are limited to {$maximumOccurrences} occurrences per submission.");
    }
}
