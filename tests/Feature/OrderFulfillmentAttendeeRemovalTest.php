<?php

declare(strict_types=1);

use App\Actions\Store\ReconcileReopenedFulfillmentEvent;
use App\Actions\Store\RecordOrderItemFulfillment;
use App\Actions\Store\RemoveReopenedFulfillmentAttendees;
use App\Actions\Store\VoidOrderItemFulfillment;
use App\Enums\FulfillmentWorkflow;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Student;
use App\Models\User;

it('removes only dancers whose event fulfillment was reopened', function (): void {
    $actor = auth()->user();
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    $reopenedStudent = Student::factory()->create();
    $remainingStudent = Student::factory()->create();
    EventAttendee::factory()->forStudent($reopenedStudent)->create(['event_id' => $event->id]);
    EventAttendee::factory()->forStudent($remainingStudent)->create(['event_id' => $event->id]);

    expect($actor)->toBeInstanceOf(User::class);

    $reopenedFulfillment = scheduledFulfillment($event, $reopenedStudent, $actor);
    scheduledFulfillment($event, $remainingStudent, $actor);
    app(VoidOrderItemFulfillment::class)->handle(
        $reopenedFulfillment,
        $actor,
        'Dancer needs to reschedule.',
    );

    expect(app(ReconcileReopenedFulfillmentEvent::class)->handle(
        event: $event,
        fulfillments: collect([$reopenedFulfillment]),
        reopenedBy: $actor,
        reason: 'Dancer needs to reschedule.',
    ))->toBeFalse()
        ->and($event->attendees()->whereMorphedTo('attendee', $reopenedStudent)->exists())->toBeFalse()
        ->and($event->attendees()->whereMorphedTo('attendee', $remainingStudent)->exists())->toBeTrue()
        ->and($event->refresh()->isCancelled())->toBeFalse();
});

it('keeps a dancer on an event while another active fulfillment still links them', function (): void {
    $actor = auth()->user();
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    $student = Student::factory()->create();
    EventAttendee::factory()->forStudent($student)->create(['event_id' => $event->id]);

    expect($actor)->toBeInstanceOf(User::class);

    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 2,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $fulfillments = app(RecordOrderItemFulfillment::class)->handle(
        orderItem: $orderItem,
        unitNumbers: [1, 2],
        fulfilledBy: $actor,
        source: $event,
        studentIds: [$student->id],
    );
    $reopenedFulfillment = $fulfillments->firstOrFail();
    app(VoidOrderItemFulfillment::class)->handle(
        $reopenedFulfillment,
        $actor,
        'Only one purchased unit should be rescheduled.',
    );

    expect(app(RemoveReopenedFulfillmentAttendees::class)->handle(
        $event,
        collect([$reopenedFulfillment]),
    ))->toBe(0)
        ->and($event->attendees()->whereMorphedTo('attendee', $student)->exists())->toBeTrue();
});

function scheduledFulfillment(Event $event, Student $student, User $actor): App\Models\OrderItemFulfillment
{
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);

    return app(RecordOrderItemFulfillment::class)->handle(
        orderItem: $orderItem,
        unitNumbers: [1],
        fulfilledBy: $actor,
        source: $event,
        studentIds: [$student->id],
    )->sole();
}
