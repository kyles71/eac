<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

it('processes payment plan installments daily at 10 a.m. Eastern', function (): void {
    $event = collect(Schedule::events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'installments:process'));

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('0 10 * * *')
        ->and($event->timezone)->toBe('America/New_York');
});
