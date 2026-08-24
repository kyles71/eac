<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('installments:process')
    ->dailyAt('10:00')
    ->timezone('America/New_York')
    ->name('process-installments')
    ->description('Process due and retryable payment plan installments');

Schedule::command('orders:cancel-abandoned')
    ->dailyAt('00:01')
    ->timezone('America/New_York')
    ->name('cancel-abandoned-orders')
    ->description('Cancel pending orders abandoned for more than 24 hours');

Schedule::command('course-holds:cancel-expired-checkouts')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('cancel-expired-course-hold-checkouts')
    ->description('Release unpaid held-seat checkout leases after 30 minutes');

Schedule::command('course-holds:send-reminders')
    ->hourly()
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->name('send-course-hold-reminders')
    ->description('Remind families about class holds expiring within 24 hours');

Schedule::command('course-holds:send-expired-emails')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('send-expired-course-hold-emails')
    ->description('Notify families when class holds expire with unpurchased seats');

Schedule::command('installments:send-past-due-notifications')
    ->dailyAt('08:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->name('send-past-due-installment-notifications')
    ->description('Notify administrators about newly past-due payment plan installments');

Schedule::command('events:send-reminders')
    ->dailyAt('08:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->name('send-event-reminders')
    ->description('Send reminders for events occurring in two weeks');

Schedule::command('enrollments:send-open-reminders')
    ->dailyAt('08:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->name('send-open-enrollment-reminders')
    ->description('Remind users to assign students to open enrollments');

Schedule::command('cart:send-abandoned-reminders')
    ->dailyAt('08:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->name('send-abandoned-cart-reminders')
    ->description('Remind users about available cart items left for at least 24 hours');

Schedule::command('backup:clean', ['--disable-notifications'])
    ->dailyAt('03:10')
    ->timezone('America/New_York')
    ->environments('production')
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure(fn () => report(new RuntimeException('Scheduled database backup cleanup failed.')))
    ->name('cleanup-database-backups')
    ->description('Remove database backups outside the configured retention policy');

Schedule::command('backup:database')
    ->dailyAt('03:40')
    ->timezone('America/New_York')
    ->environments('production')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('backup-database')
    ->description('Create an encrypted database backup on private IONOS object storage');

Schedule::command('backup:monitor')
    ->dailyAt('06:10')
    ->timezone('America/New_York')
    ->environments('production')
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure(fn () => report(new RuntimeException('Scheduled database backup monitoring failed.')))
    ->name('monitor-database-backups')
    ->description('Monitor the age and retained size of database backups');
