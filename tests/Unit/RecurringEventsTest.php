<?php

declare(strict_types=1);

use App\Enums\ScheduleFrequency;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use Carbon\Carbon;

it('does not create recurring events when the repeat through date is missing', function (): void {
    $recurring = recurringHarness();
    $data = $recurring->prepRecurringData([
        'start_time' => '2027-01-01 10:00:00',
        'end_time' => '2027-01-01 11:00:00',
        'repeat_frequency' => ScheduleFrequency::Weekly,
    ]);

    $created = $recurring->createRecurring(
        $data,
        $recurring->repeatThrough(),
        $recurring->repeatFrequency(),
        fn (array $data): array => $data,
    );

    expect($created)->toBe([]);
});

it('creates only occurrences on or before the repeat through date', function (): void {
    $recurring = recurringHarness();
    $data = $recurring->prepRecurringData([
        'start_time' => '2027-01-01 10:00:00',
        'end_time' => '2027-01-01 11:30:00',
        'repeat_frequency' => ScheduleFrequency::Weekly,
        'repeat_through' => '2027-01-10',
    ]);

    $created = $recurring->createRecurring(
        $data,
        $recurring->repeatThrough(),
        $recurring->repeatFrequency(),
        fn (array $data): array => $data,
    );

    expect($created)->toHaveCount(1)
        ->and($created[0]['start_time'])->toBe('2027-01-08 10:00:00')
        ->and($created[0]['end_time'])->toBe('2027-01-08 11:30:00');
});

it('treats repeat through as inclusive in the display timezone', function (): void {
    $recurring = recurringHarness();
    $data = $recurring->prepRecurringData([
        'start_time' => '2026-05-12 00:00:00',
        'end_time' => '2026-05-12 01:00:00',
        'repeat_frequency' => ScheduleFrequency::Daily,
        'repeat_through' => '2026-05-15',
    ]);

    $created = $recurring->createRecurring(
        $data,
        $recurring->repeatThrough(),
        $recurring->repeatFrequency(),
        fn (array $data): array => $data,
    );

    expect($created)->toHaveCount(4)
        ->and(array_column($created, 'start_time'))->toBe([
            '2026-05-13 00:00:00',
            '2026-05-14 00:00:00',
            '2026-05-15 00:00:00',
            '2026-05-16 00:00:00',
        ]);
});

it('uses no overflow monthly recurrence while preserving duration', function (): void {
    config([
        'app.timezone' => 'UTC',
        'app.display_timezone' => 'America/Detroit',
    ]);

    $recurring = recurringHarness();
    $data = $recurring->prepRecurringData([
        'start_time' => '2027-01-31 10:00:00',
        'end_time' => '2027-01-31 11:15:00',
        'repeat_frequency' => ScheduleFrequency::Monthly->value,
        'repeat_through' => '2027-03-31',
    ]);

    $created = $recurring->createRecurring(
        $data,
        $recurring->repeatThrough(),
        $recurring->repeatFrequency(),
        fn (array $data): array => $data,
    );

    expect(array_column($created, 'start_time'))->toBe([
        '2027-02-28 10:00:00',
        '2027-03-28 09:00:00',
    ])->and(array_column($created, 'end_time'))->toBe([
        '2027-02-28 11:15:00',
        '2027-03-28 10:15:00',
    ]);
});

it('preserves the local start time across the fall daylight saving transition', function (): void {
    config([
        'app.timezone' => 'UTC',
        'app.display_timezone' => 'America/Detroit',
    ]);

    $recurring = recurringHarness();
    $data = $recurring->prepRecurringData([
        'start_time' => '2026-10-25 00:20:00',
        'end_time' => '2026-10-25 01:20:00',
        'repeat_frequency' => ScheduleFrequency::Weekly,
        'repeat_through' => '2026-11-07',
    ]);

    $created = $recurring->createRecurring(
        $data,
        $recurring->repeatThrough(),
        $recurring->repeatFrequency(),
        fn (array $data): array => $data,
    );

    expect(array_column($created, 'start_time'))->toBe([
        '2026-11-01 00:20:00',
        '2026-11-08 01:20:00',
    ])->and(array_column($created, 'end_time'))->toBe([
        '2026-11-01 01:20:00',
        '2026-11-08 02:20:00',
    ]);
});

function recurringHarness(): object
{
    return new class()
    {
        use HasRecurring;

        public function repeatThrough(): ?Carbon
        {
            return $this->repeat_through;
        }

        public function repeatFrequency(): ?ScheduleFrequency
        {
            return $this->repeat_frequency;
        }
    };
}
