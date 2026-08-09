<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    private const FIRST_AFFECTED_START_TIME_UTC = '2026-11-01 04:00:00';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('events')
                ->select(['id', 'start_time', 'end_time'])
                ->where('start_time', '>=', self::FIRST_AFFECTED_START_TIME_UTC)
                ->chunkById(100, function (Collection $events): void {
                    foreach ($events as $event) {
                        DB::table('events')
                            ->where('id', $event->id)
                            ->update([
                                'start_time' => $this->addHour($event->start_time),
                                'end_time' => $event->end_time === null
                                    ? null
                                    : $this->addHour($event->end_time),
                            ]);
                    }
                });
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This historical event time correction cannot be safely reversed. Restore a database backup if reversal is required.',
        );
    }

    private function addHour(string $dateTime): string
    {
        return Carbon::parse($dateTime, 'UTC')
            ->addHour()
            ->toDateTimeString();
    }
};
