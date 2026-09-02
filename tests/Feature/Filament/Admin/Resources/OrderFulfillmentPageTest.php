<?php

declare(strict_types=1);

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Actions\Store\RecordOrderItemFulfillment;
use App\Enums\FulfillmentWorkflow;
use App\Enums\OrderItemStatus;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Orders\Pages\OrderFulfillment;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('renders the fulfillment queue and lists completed order items', function (): void {
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'fulfillment_workflow' => FulfillmentWorkflow::Manual,
    ]);

    livewire(OrderFulfillment::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$orderItem]);
});

it('records partial manual fulfillment from the queue', function (): void {
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 2,
        'fulfillment_workflow' => FulfillmentWorkflow::Manual,
    ]);

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->callAction(TestAction::make('recordManualFulfillment')->table($orderItem), [
            'unit_numbers' => [1],
            'note' => 'Picked up by purchaser.',
        ])
        ->assertNotified('Fulfillment recorded');

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::PartiallyFulfilled)
        ->and($orderItem->fulfilledQuantity())->toBe(1);
});

it('bulk changes existing outstanding items to scheduled-event fulfillment', function (): void {
    $orderItems = collect([1, 2])->map(fn (): OrderItem => OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::Manual,
    ]));

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->selectTableRecords($orderItems)
        ->callAction(TestAction::make('changeFulfillmentWorkflowBulk')->table()->bulk(), [
            'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent->value,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Fulfillment workflows changed');

    expect($orderItems->every(
        fn (OrderItem $orderItem): bool => $orderItem->refresh()->fulfillment_workflow === FulfillmentWorkflow::ScheduledEvent,
    ))->toBeTrue();
});

it('attaches an event and confirms attendees from the queue', function (): void {
    $order = Order::factory()->completed()->create();
    $student = Student::factory()->create(['user_id' => $order->user_id]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $teacher = User::factory()->isTeacher()->create();
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [$teacher->id]);

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->callAction(TestAction::make('attachFulfillmentEvent')->table($orderItem), [
            'unit_numbers' => [1],
            'event_id' => $event->id,
            'student_ids' => [$student->id],
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Event attached and fulfillment recorded');

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::Fulfilled)
        ->and($event->orderItemFulfillments()->count())->toBe(1)
        ->and($event->attendees()->whereMorphedTo('attendee', $student)->exists())->toBeTrue()
        ->and($event->attendees()->whereMorphedTo('attendee', $order->user)->exists())->toBeFalse();
});

it('does not offer an unstaffed standalone event for fulfillment', function (): void {
    $order = Order::factory()->completed()->create();
    $student = Student::factory()->create(['user_id' => $order->user_id]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->callAction(TestAction::make('attachFulfillmentEvent')->table($orderItem), [
            'unit_numbers' => [1],
            'event_id' => $event->id,
            'student_ids' => [$student->id],
        ])
        ->assertHasFormErrors(['event_id']);

    expect($orderItem->refresh()->fulfilledQuantity())->toBe(0);
});

it('creates a standalone event and links the selected units', function (): void {
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->firstOrFail();
    $order = Order::factory()->completed()->create();
    $student = Student::factory()->create(['user_id' => $order->user_id]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $teacher = User::factory()->isTeacher()->create();

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->callAction(TestAction::make('createFulfillmentEvent')->table($orderItem), [
            'unit_numbers' => [1],
            'name' => 'Avery Private Lesson',
            'calendar_id' => $calendar->id,
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(2)->addHour(),
            'description' => null,
            'details' => 'Work on turns.',
            'teacher_ids' => [$teacher->id],
            'student_ids' => [$student->id],
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Event created and fulfillment recorded');

    $event = Event::query()->where('name', 'Avery Private Lesson')->firstOrFail();

    expect($event->course_id)->toBeNull()
        ->and($event->calendar_id)->toBe($calendar->id)
        ->and($event->orderItemFulfillments()->count())->toBe(1)
        ->and($event->teachers->modelKeys())->toBe([$teacher->id])
        ->and($event->attendees()->whereMorphedTo('attendee', $student)->exists())->toBeTrue()
        ->and($event->attendees()->whereMorphedTo('attendee', $order->user)->exists())->toBeFalse()
        ->and($orderItem->refresh()->status)->toBe(OrderItemStatus::Fulfilled);
});

it('requires staffing before creating a fulfillment event', function (): void {
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->firstOrFail();
    $order = Order::factory()->completed()->create();
    $student = Student::factory()->create(['user_id' => $order->user_id]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->callAction(TestAction::make('createFulfillmentEvent')->table($orderItem), [
            'unit_numbers' => [1],
            'name' => 'Unstaffed Private Lesson',
            'calendar_id' => $calendar->id,
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(2)->addHour(),
            'teacher_ids' => [],
            'student_ids' => [$student->id],
        ])
        ->assertHasFormErrors(['teacher_ids']);

    expect(Event::query()->where('name', 'Unstaffed Private Lesson')->exists())->toBeFalse()
        ->and($orderItem->refresh()->fulfilledQuantity())->toBe(0);
});

it('creates one staffed event atomically for shared fulfillment', function (): void {
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->firstOrFail();
    $teacher = User::factory()->isTeacher()->create();
    $student = Student::factory()->create();
    $orderItems = collect([1, 2])->map(fn (): OrderItem => OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]));

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->selectTableRecords($orderItems)
        ->callAction(TestAction::make('createFulfillmentEventBulk')->table()->bulk(), [
            'name' => 'Shared Private Lesson',
            'calendar_id' => $calendar->id,
            'start_time' => now()->addDays(3),
            'end_time' => now()->addDays(3)->addHour(),
            'teacher_ids' => [$teacher->id],
            'student_ids' => [$student->id],
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Shared event created and fulfillment recorded');

    $event = Event::query()->where('name', 'Shared Private Lesson')->sole();

    expect($event->teachers->modelKeys())->toBe([$teacher->id])
        ->and($event->orderItemFulfillments)->toHaveCount(2)
        ->and($orderItems->every(fn (OrderItem $orderItem): bool => $orderItem->refresh()->fulfilledQuantity() === 1))->toBeTrue();
});

it('offers only student invitees and defaults new events to the staff calendar', function (): void {
    $staffCalendar = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->firstOrFail();
    $order = Order::factory()->completed()->create();
    Student::factory()->create(['user_id' => $order->user_id]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->mountAction(TestAction::make('createFulfillmentEvent')->table($orderItem))
        ->assertSchemaComponentDoesNotExist('user_ids', 'mountedActionSchema0')
        ->assertSchemaComponentExists('student_ids', 'mountedActionSchema0')
        ->assertSchemaComponentStateSet('calendar_id', $staffCalendar->id, 'mountedActionSchema0');

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->mountAction(TestAction::make('attachFulfillmentEvent')->table($orderItem))
        ->assertSchemaComponentDoesNotExist('user_ids', 'mountedActionSchema0')
        ->assertSchemaComponentExists('student_ids', 'mountedActionSchema0');
});

it('shows the fulfillment link on both the order and event', function (): void {
    $order = Order::factory()->completed()->create();
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $event = Event::factory()->standalone()->create([
        'name' => 'Avery Private Lesson',
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    app(RecordOrderItemFulfillment::class)->handle($orderItem, [1], $actor, $event);

    livewire(ViewOrder::class, ['record' => $order->id])
        ->assertSee('Fulfillment History')
        ->assertSee('Event: Avery Private Lesson');

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertSee('Order Fulfillment')
        ->assertSee($orderItem->product->name)
        ->assertSee($order->user->full_name);
});

it('requires the dedicated fulfillment permission', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['ViewAny:Order', 'View:Order']);
    $this->actingAs($viewer);

    livewire(OrderFulfillment::class)
        ->assertForbidden();
});
