<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('corrects all existing events scheduled on or after November 1 regardless of their ids', function (): void {
    DB::table('events')->insert([
        [
            'id' => 101,
            'name' => 'Before the cutoff',
            'start_time' => '2026-11-01 03:59:59',
            'end_time' => '2026-11-01 04:59:59',
        ],
        [
            'id' => 205,
            'name' => 'At the cutoff',
            'start_time' => '2026-11-01 04:00:00',
            'end_time' => '2026-11-01 05:00:00',
        ],
        [
            'id' => 999,
            'name' => 'Later without an end time',
            'start_time' => '2027-01-15 15:00:00',
            'end_time' => null,
        ],
    ]);

    $migration = require database_path('migrations/2026_08_01_193129_correct_dst_shifted_recurring_event_times.php');
    $migration->up();

    expect(DB::table('events')->where('id', 101)->first())
        ->start_time->toBe('2026-11-01 03:59:59')
        ->end_time->toBe('2026-11-01 04:59:59')
        ->and(DB::table('events')->where('id', 205)->first())
        ->start_time->toBe('2026-11-01 05:00:00')
        ->end_time->toBe('2026-11-01 06:00:00')
        ->and(DB::table('events')->where('id', 999)->first())
        ->start_time->toBe('2027-01-15 16:00:00')
        ->end_time->toBeNull();

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'cannot be safely reversed');
});
