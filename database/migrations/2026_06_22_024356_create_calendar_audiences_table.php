<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Tags\Tag;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_audiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->morphs('audience');
            $table->timestamps();

            $table->unique(
                ['calendar_id', 'audience_type', 'audience_id'],
                'calendar_audiences_unique',
            );
        });

        $systemCalendarIds = DB::table('calendars')
            ->whereIn('slug', ['my', 'eac', 'owners', 'staff', 'comp'])
            ->pluck('id');
        $publicTagId = Tag::findFromString('Public', 'calendar-audience')?->id;

        DB::table('calendars')
            ->whereIn('id', $systemCalendarIds)
            ->update(['access' => null]);

        DB::table('calendars')
            ->whereNotIn('id', $systemCalendarIds)
            ->orderBy('id')
            ->get()
            ->each(function (object $calendar) use ($publicTagId): void {
                $audienceTagIds = DB::table('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->where('taggables.taggable_type', 'App\\Models\\Calendar')
                    ->where('taggables.taggable_id', $calendar->id)
                    ->where('tags.type', 'calendar-audience')
                    ->pluck('tags.id');

                DB::table('calendars')
                    ->where('id', $calendar->id)
                    ->update([
                        'access' => $publicTagId !== null && $audienceTagIds->contains($publicTagId)
                            ? 'public'
                            : 'restricted',
                    ]);

                $restrictedTagIds = $audienceTagIds
                    ->when(
                        $publicTagId !== null,
                        fn ($tagIds) => $tagIds->reject(fn (int $tagId): bool => $tagId === $publicTagId),
                    );

                if ($restrictedTagIds->isEmpty()) {
                    return;
                }

                DB::table('taggables')
                    ->whereIn('tag_id', $restrictedTagIds)
                    ->whereIn('taggable_type', ['App\\Models\\User', 'App\\Models\\Student'])
                    ->get(['taggable_type', 'taggable_id'])
                    ->unique(fn (object $taggable): string => $taggable->taggable_type.':'.$taggable->taggable_id)
                    ->each(function (object $taggable) use ($calendar): void {
                        DB::table('calendar_audiences')->insertOrIgnore([
                            'calendar_id' => $calendar->id,
                            'audience_type' => $taggable->taggable_type,
                            'audience_id' => $taggable->taggable_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
            });

        $systemAudienceTagIds = DB::table('tags')
            ->where('type', 'calendar-audience')
            ->pluck('id');

        DB::table('taggables')
            ->where('taggable_type', 'App\\Models\\Calendar')
            ->whereIn('taggable_id', $systemCalendarIds)
            ->whereIn('tag_id', $systemAudienceTagIds)
            ->delete();

        DB::table('tags')
            ->where('type', 'calendar-audience')
            ->delete();
    }

    public function down(): void
    {
        DB::table('calendars')
            ->whereNotIn('slug', ['my', 'eac', 'owners', 'staff', 'comp'])
            ->orderBy('id')
            ->get()
            ->each(function (object $calendar): void {
                if ($calendar->access === 'public') {
                    $this->attachTag('App\\Models\\Calendar', (int) $calendar->id, 'Public');

                    return;
                }

                $tagName = 'Calendar: '.$calendar->slug;
                $this->attachTag('App\\Models\\Calendar', (int) $calendar->id, $tagName);

                DB::table('calendar_audiences')
                    ->where('calendar_id', $calendar->id)
                    ->get()
                    ->each(function (object $audience) use ($tagName): void {
                        if (in_array($audience->audience_type, ['App\\Models\\User', 'App\\Models\\Student'], true)) {
                            $this->attachTag($audience->audience_type, (int) $audience->audience_id, $tagName);

                            return;
                        }

                        if ($audience->audience_type !== 'App\\Models\\Course') {
                            return;
                        }

                        DB::table('course_teacher')
                            ->where('course_id', $audience->audience_id)
                            ->pluck('teacher_id')
                            ->each(fn (int $userId) => $this->attachTag('App\\Models\\User', $userId, $tagName));

                        DB::table('enrollments')
                            ->where('course_id', $audience->audience_id)
                            ->whereNotNull('student_id')
                            ->pluck('student_id')
                            ->each(fn (int $studentId) => $this->attachTag('App\\Models\\Student', $studentId, $tagName));
                    });
            });

        foreach ([
            'my' => 'Public',
            'eac' => 'Public',
            'owners' => 'Owners',
            'staff' => 'Staff',
        ] as $slug => $tagName) {
            $calendarId = DB::table('calendars')->where('slug', $slug)->value('id');

            if ($calendarId === null) {
                continue;
            }

            $this->attachTag('App\\Models\\Calendar', (int) $calendarId, $tagName);
        }

        DB::table('calendars')->update(['access' => null]);

        Schema::dropIfExists('calendar_audiences');
    }

    private function attachTag(string $taggableType, int $taggableId, string $tagName): void
    {
        DB::table('taggables')->updateOrInsert([
            'tag_id' => Tag::findOrCreate($tagName, 'calendar-audience')->id,
            'taggable_type' => $taggableType,
            'taggable_id' => $taggableId,
        ]);
    }
};
