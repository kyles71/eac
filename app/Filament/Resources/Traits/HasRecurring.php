<?php

namespace App\Filament\Resources\Traits;

use App\Enums\ScheduleFrequency;
use Carbon\Carbon;
use Closure;

trait HasRecurring {
    public function prepRecurringData(array $data): array{
        $repeat_frequency = $data['repeat_frequency'] ?? null;
        $repeat_through = isset($data['repeat_through']) ? Carbon::create($data['repeat_through']) : null;

        unset($data['repeat_frequency'], $data['repeat_through']);

        return [
            'data' => $data,
            'repeat_frequency' => $repeat_frequency,
            'repeat_through' => $repeat_through,
        ];
    }

    public function createRecurring(array $data, ?Carbon $repeat_through, ?ScheduleFrequency $repeat_frequency, Closure $create_method, ?string $start_field = 'start_time', ?string $end_field = 'end_time'): array
    {
        $return = [];

        if (!$create_method) {
            return $return;
        }

        if (!$repeat_frequency) {
            return $return;
        }

        $repeat_through->endOfDay();
        $start = Carbon::create($data[$start_field]);
        $end = Carbon::create($data[$end_field]);

        while (isset($repeat_through, $repeat_frequency) && $start->lt($repeat_through)) {
            switch ($repeat_frequency) {
                case ScheduleFrequency::DAILY:
                    $start->addDay();
                    $end->addDay();
                    break;
                case ScheduleFrequency::WEEKLY:
                    $start->addWeek();
                    $end->addWeek();
                    break;
                case ScheduleFrequency::BIWEEKLY:
                    $start->addWeeks(2);
                    $end->addWeeks(2);
                    break;
                case ScheduleFrequency::MONTHLY:
                    $start->addMonth();
                    $end->addMonth();
                    break;
            }

            $data[$start_field] = Carbon::create($start)->toDateTimeString();
            $data[$end_field] = Carbon::create($end)->toDateTimeString();

            $return[] = $create_method($data);
        }

        return $return;
    }
}
