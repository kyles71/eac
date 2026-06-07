<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Enrollment;
use Carbon\Carbon;

final class EnrollmentStatus
{
    public static function for(Enrollment $enrollment, ?Carbon $now = null): string
    {
        $course = $enrollment->course;

        if ($course === null) {
            return 'Past';
        }

        $now ??= Carbon::now();

        if ($course->hasConcluded($now)) {
            return 'Past';
        }

        if ($enrollment->student_id === null) {
            return 'Open';
        }

        if ($course->start_time?->gt($now)) {
            return 'Future';
        }

        return 'Active';
    }

    public static function color(Enrollment $enrollment): string
    {
        return match (self::for($enrollment)) {
            'Open' => 'warning',
            'Active' => 'success',
            'Future' => 'info',
            default => 'gray',
        };
    }
}
