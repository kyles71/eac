<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('calendars', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('id');
            $table->unique('slug');
        });

        $myCalendarId = DB::table('calendars')->where('id', 1)->value('id')
            ?? DB::table('calendars')->where('name', 'My Calendar')->value('id');

        if ($myCalendarId !== null) {
            DB::table('calendars')->where('id', $myCalendarId)->update([
                'slug' => 'my',
            ]);
        }

        $eacCalendarId = DB::table('calendars')->where('id', 2)->value('id')
            ?? DB::table('calendars')->where('name', 'EAC Calendar')->value('id');

        if ($eacCalendarId !== null) {
            DB::table('calendars')->where('id', $eacCalendarId)->update([
                'slug' => 'eac',
            ]);
        }

        foreach ($this->systemCalendars() as $slug => $calendar) {
            $this->upsertSystemCalendar($slug, $calendar);
        }

        $reservedSlugs = array_keys($this->systemCalendars());

        DB::table('calendars')
            ->whereNull('slug')
            ->orderBy('id')
            ->get()
            ->each(function (object $calendar) use (&$reservedSlugs): void {
                $baseSlug = Str::slug((string) $calendar->name) ?: 'calendar';
                $slug = $baseSlug;
                $suffix = 2;

                while (in_array($slug, $reservedSlugs, true)) {
                    $slug = $baseSlug.'-'.$suffix;
                    $suffix++;
                }

                $reservedSlugs[] = $slug;

                DB::table('calendars')
                    ->where('id', $calendar->id)
                    ->update(['slug' => $slug]);
            });

        if (Schema::hasTable('tags')) {
            DB::table('tags')
                ->where('type', 'calendar-access')
                ->update(['type' => 'calendar-audience']);

            $this->seedSystemAudienceTags();
            $this->seedCourseCalendarTags();
            $this->grantDefaultAudienceTagsToRoleUsers();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tags')) {
            DB::table('tags')
                ->where('type', 'calendar-audience')
                ->update(['type' => 'calendar-access']);
        }

        Schema::table('calendars', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    /**
     * @return array<string, array{name: string, background_color: ?string}>
     */
    private function systemCalendars(): array
    {
        return [
            'my' => [
                'name' => 'My Calendar',
                'background_color' => null,
            ],
            'eac' => [
                'name' => 'EAC Calendar',
                'background_color' => '#ff5733',
            ],
            'owners' => [
                'name' => 'Owners',
                'background_color' => '#2563eb',
            ],
            'staff' => [
                'name' => 'Staff',
                'background_color' => '#16a34a',
            ],
            'comp' => [
                'name' => 'Comp Calendar',
                'background_color' => '#9333ea',
            ],
        ];
    }

    /**
     * @param  array{name: string, background_color: ?string}  $calendar
     */
    private function upsertSystemCalendar(string $slug, array $calendar): void
    {
        $matches = DB::table('calendars')
            ->where('slug', $slug)
            ->orWhere('name', $calendar['name'])
            ->orderByRaw('case when slug = ? then 0 else 1 end', [$slug])
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            DB::table('calendars')->insert([
                ...$calendar,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $keeper = $matches->first();
        $duplicateIds = $matches
            ->skip(1)
            ->pluck('id')
            ->all();

        if ($duplicateIds !== []) {
            if (Schema::hasTable('events')) {
                DB::table('events')
                    ->whereIn('calendar_id', $duplicateIds)
                    ->update(['calendar_id' => $keeper->id]);
            }

            if (Schema::hasTable('taggables')) {
                DB::table('taggables')
                    ->where('taggable_type', 'App\\Models\\Calendar')
                    ->whereIn('taggable_id', $duplicateIds)
                    ->delete();
            }

            DB::table('calendars')
                ->whereIn('id', $duplicateIds)
                ->delete();
        }

        DB::table('calendars')
            ->where('id', $keeper->id)
            ->update([
                ...$calendar,
                'slug' => $slug,
                'updated_at' => now(),
            ]);
    }

    private function seedSystemAudienceTags(): void
    {
        foreach ($this->systemAudienceTags() as $slug => $tagName) {
            $calendarId = DB::table('calendars')
                ->where('slug', $slug)
                ->value('id');

            if ($calendarId === null) {
                continue;
            }

            $this->attachAudienceTag('App\\Models\\Calendar', (int) $calendarId, $tagName);
        }
    }

    private function seedCourseCalendarTags(): void
    {
        foreach (['eac', 'comp'] as $calendarSlug) {
            Tag::findOrCreate($calendarSlug, 'course-calendar');
        }

        if (! Schema::hasTable('courses')) {
            return;
        }

        $eacTagId = (int) Tag::findOrCreate('eac', 'course-calendar')->id;

        DB::table('courses')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $courseId) use ($eacTagId): void {
                $hasCalendarTags = DB::table('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->where('taggables.taggable_type', 'App\\Models\\Course')
                    ->where('taggables.taggable_id', $courseId)
                    ->where('tags.type', 'course-calendar')
                    ->exists();

                if ($hasCalendarTags) {
                    return;
                }

                DB::table('taggables')->updateOrInsert([
                    'tag_id' => $eacTagId,
                    'taggable_type' => 'App\\Models\\Course',
                    'taggable_id' => $courseId,
                ]);
            });
    }

    /**
     * @return array<string, string>
     */
    private function systemAudienceTags(): array
    {
        return [
            'my' => 'Public',
            'eac' => 'Public',
            'owners' => 'Owners',
            'staff' => 'Staff',
        ];
    }

    private function grantDefaultAudienceTagsToRoleUsers(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $this->grantAudienceTagsToRoleUsers('super_admin', ['Owners', 'Staff']);
        $this->grantAudienceTagsToRoleUsers('owner', ['Owners', 'Staff']);
        $this->grantAudienceTagsToRoleUsers('teacher', ['Staff']);
    }

    /**
     * @param  array<int, string>  $tagNames
     */
    private function grantAudienceTagsToRoleUsers(string $roleName, array $tagNames): void
    {
        $roleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->where('name', $roleName)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $userIds = DB::table('model_has_roles')
            ->where('model_type', 'App\\Models\\User')
            ->whereIn('role_id', $roleIds)
            ->pluck('model_id');

        foreach ($userIds as $userId) {
            foreach ($tagNames as $tagName) {
                $this->attachAudienceTag('App\\Models\\User', (int) $userId, $tagName);
            }
        }
    }

    private function attachAudienceTag(string $taggableType, int $taggableId, string $tagName): void
    {
        DB::table('taggables')->updateOrInsert([
            'tag_id' => $this->audienceTagId($tagName),
            'taggable_type' => $taggableType,
            'taggable_id' => $taggableId,
        ]);
    }

    private function audienceTagId(string $tagName): int
    {
        return (int) Tag::findOrCreate($tagName, 'calendar-audience')->id;
    }
};
