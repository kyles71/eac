<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Models\Course;
use Illuminate\Support\HtmlString;

final class CourseStaffPresenter
{
    public static function render(?Course $course): ?HtmlString
    {
        if ($course === null) {
            return null;
        }

        if (filled($course->guest_teacher)) {
            return new HtmlString(e($course->guest_teacher));
        }

        $course->loadMissing('teachers.media');

        if ($course->teachers->isEmpty()) {
            return null;
        }

        return new HtmlString(view('filament.shared.course-staff-names', [
            'staffMembers' => $course->teachers,
        ])->render());
    }
}
