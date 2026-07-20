<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schedule;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

it('configures encrypted database-only backups on private ionos storage', function (): void {
    expect(config('backup.backup.source.files.include'))->toBe([])
        ->and(config('backup.backup.source.databases'))->toBe([config('database.default')])
        ->and(config('backup.backup.destination.disks'))->toBe(['ionos_private'])
        ->and(config('filesystems.disks.ionos_private.visibility'))->toBe('private')
        ->and(config('backup.backup.encryption'))->toBe('aes256')
        ->and(config('backup.backup.verify_backup'))->toBeTrue()
        ->and(config('backup.backup.tries'))->toBe(3)
        ->and(config('backup.backup.retry_delay'))->toBe(60)
        ->and(config('backup.notifications.notifications'))->toBe([])
        ->and(config('database.connections.mysql.dump'))->toMatchArray([
            'use_single_transaction' => true,
            'use_quick' => true,
            'timeout' => 600,
        ])
        ->and(config('database.connections.mariadb.dump'))->toMatchArray([
            'use_single_transaction' => true,
            'use_quick' => true,
            'timeout' => 600,
        ]);
});

it('uses the agreed backup health and tiered retention policy', function (): void {
    $monitor = config('backup.monitor_backups.0');
    $cleanup = config('backup.cleanup');

    expect($monitor['name'])->toBe(config('backup.backup.name'))
        ->and($monitor['disks'])->toBe(['ionos_private'])
        ->and($monitor['health_checks'])->toBe([
            MaximumAgeInDays::class => 1,
            MaximumStorageInMegabytes::class => 5000,
        ])
        ->and($cleanup['strategy'])->toBe(DefaultStrategy::class)
        ->and($cleanup['default_strategy'])->toBe([
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 4,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ])
        ->and($cleanup['tries'])->toBe(3)
        ->and($cleanup['retry_delay'])->toBe(60);
});

it('schedules production backup operations without overlap', function (): void {
    $events = collect(Schedule::events());

    assertScheduledBackupEvent($events, 'backup:clean', '10 3 * * *', ['--disable-notifications']);
    assertScheduledBackupEvent($events, 'backup:database', '40 3 * * *');
    assertScheduledBackupEvent($events, 'backup:monitor', '10 6 * * *');

    expect(scheduledBackupEvent('backup:monitor', $events)->command)
        ->not->toContain('--disable-notifications');
});

it('reports scheduled cleanup and monitoring failures', function (): void {
    $exceptionHandler = Mockery::mock(ExceptionHandler::class);
    $exceptionHandler
        ->shouldReceive('report')
        ->once()
        ->with(Mockery::on(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Scheduled database backup cleanup failed.'));
    $exceptionHandler
        ->shouldReceive('report')
        ->once()
        ->with(Mockery::on(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Scheduled database backup monitoring failed.'));

    $this->app->instance(ExceptionHandler::class, $exceptionHandler);

    scheduledBackupEvent('backup:clean')->finish($this->app, Command::FAILURE);
    scheduledBackupEvent('backup:monitor')->finish($this->app, Command::FAILURE);
});

/** @param Collection<int, Event> $events */
function assertScheduledBackupEvent(
    Collection $events,
    string $command,
    string $expression,
    array $commandOptions = [],
): void {
    $event = scheduledBackupEvent($command, $events);

    expect($event->expression)->toBe($expression)
        ->and($event->timezone)->toBe('America/New_York')
        ->and($event->environments)->toBe(['production'])
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();

    foreach ($commandOptions as $option) {
        expect($event->command)->toContain($option);
    }
}

/** @param Collection<int, Event>|null $events */
function scheduledBackupEvent(string $command, ?Collection $events = null): Event
{
    $event = ($events ?? collect(Schedule::events()))
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', $command));

    expect($event)->toBeInstanceOf(Event::class);

    return $event;
}
