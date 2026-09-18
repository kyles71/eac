<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\Pages;

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Actions\RecurringPrivateLessons\UpdateRecurringPrivateLessonStatus;
use App\Enums\CourseTeacherAssignmentStrategy;
use App\Enums\RecurringPrivateLessonStatus;
use App\Filament\Admin\Resources\RecurringPrivateLessons\RecurringPrivateLessonResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EditRecurringPrivateLesson extends EditRecord
{
    protected static string $resource = RecurringPrivateLessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof \App\Models\RecurringPrivateLesson, 404);

        return DB::transaction(function () use ($record, $data): Model {
            $record->course()->update([
                'name' => (string) $data['course_name'],
                'description' => filled($data['course_description'] ?? null) ? (string) $data['course_description'] : null,
                'semester' => $data['semester'],
            ]);
            app(ManageEventTeacherAssignments::class)->updateCourseRoster(
                $record->course,
                array_map('intval', $data['teacher_ids']),
                $data['teacher_assignment_strategy'] instanceof CourseTeacherAssignmentStrategy
                    ? $data['teacher_assignment_strategy']
                    : CourseTeacherAssignmentStrategy::from($data['teacher_assignment_strategy']),
            );
            $record->update([
                'lesson_price' => (int) round(((float) $data['lesson_price_dollars']) * 100),
            ]);
            app(UpdateRecurringPrivateLessonStatus::class)->handle(
                $record,
                $data['status'] instanceof RecurringPrivateLessonStatus
                    ? $data['status']
                    : RecurringPrivateLessonStatus::from($data['status']),
            );

            return $record->refresh();
        });
    }
}
