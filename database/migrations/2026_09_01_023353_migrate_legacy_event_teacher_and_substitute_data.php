<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        DB::table('courses')->update([
            'teacher_assignment_strategy' => 'all_teachers',
        ]);

        DB::table('events')->whereNotNull('course_id')->update([
            'teacher_assignment_mode' => 'course_defaults',
        ]);
        DB::table('events')->whereNull('course_id')->update([
            'teacher_assignment_mode' => 'custom',
        ]);

        $legacySubstituteEvents = $this->legacySubstituteEvents();
        $this->backfillCourseTeacherOrder();

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('events', function (Blueprint $table): void {
                $table->dropForeign(['substitute_teacher_id']);
                $table->dropColumn(['substitute_teacher_id', 'substitute_needed_at']);
            });
        });

        $this->backfillEventAssignmentsAndSequence();
        $this->backfillSubstituteCoverage($legacySubstituteEvents);
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('events', function (Blueprint $table): void {
                $table->foreignId('substitute_teacher_id')
                    ->nullable()
                    ->after('course_id')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('substitute_needed_at')
                    ->nullable()
                    ->after('substitute_teacher_id');
            });
        });

        DB::table('event_substitute_coverages')
            ->orderBy('id')
            ->get()
            ->groupBy('event_id')
            ->each(function ($coverages, int|string $eventId): void {
                $coverage = $coverages->first(
                    fn (object $coverage): bool => $coverage->substitute_teacher_id !== null,
                ) ?? $coverages->first();

                if ($coverage === null) {
                    return;
                }

                DB::table('events')
                    ->where('id', (int) $eventId)
                    ->update([
                        'substitute_teacher_id' => $coverage->substitute_teacher_id,
                        'substitute_needed_at' => $coverage->needed_at,
                    ]);
            });
    }

    private function backfillCourseTeacherOrder(): void
    {
        DB::table('course_teacher')
            ->select('course_id')
            ->distinct()
            ->orderBy('course_id')
            ->pluck('course_id')
            ->each(function (int $courseId): void {
                DB::table('course_teacher')
                    ->where('course_id', $courseId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->values()
                    ->each(fn (int $id, int $position) => DB::table('course_teacher')
                        ->where('id', $id)
                        ->update(['rotation_position' => $position + 1]));
            });
    }

    private function backfillEventAssignmentsAndSequence(): void
    {
        $now = now();

        DB::table('events')
            ->whereNotNull('course_id')
            ->select('course_id')
            ->distinct()
            ->orderBy('course_id')
            ->pluck('course_id')
            ->each(function (int $courseId) use ($now): void {
                $teacherIds = DB::table('course_teacher')
                    ->where('course_id', $courseId)
                    ->orderBy('rotation_position')
                    ->orderBy('id')
                    ->pluck('teacher_id');
                $events = DB::table('events')
                    ->where('course_id', $courseId)
                    ->orderBy('start_time')
                    ->orderBy('id')
                    ->get(['id']);

                foreach ($events as $eventIndex => $event) {
                    DB::table('events')
                        ->where('id', $event->id)
                        ->update(['teacher_rotation_sequence' => $eventIndex + 1]);

                    $assignments = $teacherIds
                        ->map(fn (int $teacherId): array => [
                            'event_id' => $event->id,
                            'teacher_id' => $teacherId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])
                        ->all();

                    if ($assignments !== []) {
                        DB::table('event_teacher_assignments')->insertOrIgnore($assignments);
                    }
                }
            });
    }

    private function legacySubstituteEvents(): Illuminate\Support\Collection
    {
        $eventIds = DB::table('events')
            ->whereNotNull('substitute_teacher_id')
            ->orWhereNotNull('substitute_needed_at')
            ->pluck('id')
            ->merge(DB::table('event_substitute_requests')->pluck('event_id'))
            ->unique()
            ->sort()
            ->values();

        return DB::table('events')
            ->whereIn('id', $eventIds)
            ->get([
                'id',
                'substitute_teacher_id',
                'substitute_needed_at',
                'cancelled_at',
                'cancelled_by_user_id',
                'created_at',
                'updated_at',
            ]);
    }

    private function backfillSubstituteCoverage(Illuminate\Support\Collection $events): void
    {
        foreach ($events as $event) {
            $eventId = $event->id;

            $teacherIds = DB::table('event_teacher_assignments')
                ->where('event_id', $eventId)
                ->whereNotNull('teacher_id')
                ->pluck('teacher_id');
            $firstRequest = DB::table('event_substitute_requests')
                ->where('event_id', $eventId)
                ->oldest('id')
                ->first();
            $neededAt = $event->substitute_needed_at;

            if ($neededAt === null && ($event->substitute_teacher_id !== null || $firstRequest !== null)) {
                $neededAt = $firstRequest?->created_at ?? $event->updated_at;
            }

            $coverageId = DB::table('event_substitute_coverages')->insertGetId([
                'event_id' => $eventId,
                'covered_teacher_id' => $teacherIds->count() === 1 ? $teacherIds->first() : null,
                'substitute_teacher_id' => $event->substitute_teacher_id,
                'needed_at' => $neededAt,
                'closed_at' => $event->cancelled_at,
                'closed_by_user_id' => $event->cancelled_by_user_id,
                'closure_reason' => $event->cancelled_at !== null ? 'The event was cancelled.' : null,
                'created_at' => $firstRequest?->created_at ?? $event->created_at,
                'updated_at' => $event->updated_at,
            ]);

            DB::table('event_substitute_requests')
                ->where('event_id', $eventId)
                ->update(['event_substitute_coverage_id' => $coverageId]);
        }
    }
};
