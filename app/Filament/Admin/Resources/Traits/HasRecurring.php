<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Traits;

use App\Enums\ScheduleFrequency;
use Carbon\Carbon;
use Closure;

trait HasRecurring
{
    private $repeat_through;

    private $repeat_frequency;

    public function prepRecurringData(array $data): array
    {
        $this->repeat_frequency = $data['repeat_frequency'] ?? null;
        $this->repeat_through = isset($data['repeat_through']) ? Carbon::create($data['repeat_through']) : null;
        $this->attendees_list = $data['attendees_list'] ?? [];

        unset($data['repeat_frequency'], $data['repeat_through']);

        return $data;
    }

    public function createRecurring(array $data, ?Carbon $repeat_through, ?ScheduleFrequency $repeat_frequency, Closure $create_method, ?string $start_field = 'start_time', ?string $end_field = 'end_time'): array
    {
        $return = [];

        if (! $create_method) {
            return $return;
        }

        if (!$repeat_frequency instanceof \App\Enums\ScheduleFrequency) {
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
