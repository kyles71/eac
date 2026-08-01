<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;

final readonly class CourseEmailRecipientsService
{
    /**
     * @return array<int, Student|string>
     */
    public function forCourse(Course $course): array
    {
        return Enrollment::query()
            ->where('course_id', $course->id)
            ->with(['student', 'user'])
            ->get()
            ->flatMap(function (Enrollment $enrollment): array {
                if ($enrollment->student === null) {
                    return [$enrollment->user->email];
                }

                if ($enrollment->student->user_id !== $enrollment->user_id) {
                    return [$enrollment->student, $enrollment->user->email];
                }

                return [$enrollment->student];
            })
            ->all();
    }
}
