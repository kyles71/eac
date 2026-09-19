<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Carbon::setTestNow('2026-09-18 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sorts events chronologically and filters future, past, and my events', function (): void {
    $user = auth()->user();

    expect($user)->toBeInstanceOf(User::class);

    $earlierPastEvent = Event::factory()->standalone()->create([
        'name' => 'Earlier Past Event',
        'start_time' => now()->subDays(3),
        'end_time' => now()->subDays(3)->addHour(),
    ]);
    $myPastEvent = Event::factory()->standalone()->create([
        'name' => 'My Past Event',
        'start_time' => now()->subDay(),
        'end_time' => now()->subDay()->addHour(),
    ]);
    $myFutureEvent = Event::factory()->standalone()->create([
        'name' => 'My Future Event',
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $laterFutureEvent = Event::factory()->standalone()->create([
        'name' => 'Later Future Event',
        'start_time' => now()->addDays(2),
        'end_time' => now()->addDays(2)->addHour(),
    ]);

    $myPastEvent->teachers()->sync([$user->id]);
    $myFutureEvent->teachers()->sync([$user->id]);

    livewire(ListEvents::class)
        ->assertSee('All Events')
        ->assertSee('Future Events')
        ->assertSee('Past Events')
        ->assertSee('My Events')
        ->loadTable()
        ->assertCanSeeTableRecords([
            $earlierPastEvent,
            $myPastEvent,
            $myFutureEvent,
            $laterFutureEvent,
        ], inOrder: true)
        ->set('activeTab', 'future')
        ->loadTable()
        ->assertCanSeeTableRecords([$myFutureEvent, $laterFutureEvent], inOrder: true)
        ->assertCanNotSeeTableRecords([$earlierPastEvent, $myPastEvent])
        ->set('activeTab', 'past')
        ->loadTable()
        ->assertCanSeeTableRecords([$earlierPastEvent, $myPastEvent], inOrder: true)
        ->assertCanNotSeeTableRecords([$myFutureEvent, $laterFutureEvent])
        ->set('activeTab', 'mine')
        ->loadTable()
        ->assertCanSeeTableRecords([$myFutureEvent])
        ->assertCanNotSeeTableRecords([$earlierPastEvent, $myPastEvent, $laterFutureEvent]);
});
