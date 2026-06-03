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
        DB::table('event_attendees')
            ->select([
                'event_id',
                'attendee_type',
                'attendee_id',
                DB::raw('MIN(id) as keeper_id'),
                DB::raw('MAX(attended) as attended'),
            ])
            ->groupBy('event_id', 'attendee_type', 'attendee_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('keeper_id')
            ->get()
            ->each(function (object $duplicate): void {
                $duplicateIds = DB::table('event_attendees')
                    ->where('event_id', $duplicate->event_id)
                    ->where('attendee_type', $duplicate->attendee_type)
                    ->where('attendee_id', $duplicate->attendee_id)
                    ->where('id', '!=', $duplicate->keeper_id)
                    ->pluck('id');
                $allDuplicateIds = $duplicateIds
                    ->merge([$duplicate->keeper_id])
                    ->unique()
                    ->values();

                $notes = DB::table('event_attendees')
                    ->whereIn('id', $allDuplicateIds)
                    ->whereNotNull('notes')
                    ->orderBy('id')
                    ->value('notes');

                DB::table('event_attendees')
                    ->where('id', $duplicate->keeper_id)
                    ->update([
                        'attended' => (bool) $duplicate->attended,
                        'notes' => $notes,
                    ]);

                DB::table('event_attendees')
                    ->whereIn('id', $duplicateIds)
                    ->delete();
            });

        Schema::table('event_attendees', function (Blueprint $table): void {
            $table->unique(
                ['event_id', 'attendee_type', 'attendee_id'],
                'event_attendees_event_attendee_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('event_attendees', function (Blueprint $table): void {
            $table->dropUnique('event_attendees_event_attendee_unique');
        });
    }
};
