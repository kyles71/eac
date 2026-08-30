<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\Pages;

use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson as CreateRecurringPrivateLessonAction;
use App\Enums\CourseSemester;
use App\Enums\ScheduleFrequency;
use App\Filament\Admin\Resources\RecurringPrivateLessons\RecurringPrivateLessonResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateRecurringPrivateLesson extends CreateRecord
{
    protected static string $resource = RecurringPrivateLessonResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateRecurringPrivateLessonAction::class)->handle(
            household: \App\Models\User::query()->findOrFail($data['user_id']),
            student: \App\Models\Student::query()->findOrFail($data['student_id']),
            teacherIds: array_map('intval', $data['teacher_ids']),
            name: (string) $data['course_name'],
            description: filled($data['course_description'] ?? null) ? (string) $data['course_description'] : null,
            semester: $data['semester'] instanceof CourseSemester
                ? $data['semester']
                : CourseSemester::from($data['semester']),
            lessonPrice: (int) round(((float) $data['lesson_price_dollars']) * 100),
            startsAt: \Carbon\CarbonImmutable::parse($data['starts_at']),
            durationMinutes: (int) $data['duration_minutes'],
            repeatThrough: \Carbon\CarbonImmutable::parse($data['repeat_through']),
            frequency: $data['repeat_frequency'] instanceof ScheduleFrequency
                ? $data['repeat_frequency']
                : ScheduleFrequency::from($data['repeat_frequency']),
        );
    }
}
