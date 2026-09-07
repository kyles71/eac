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
use App\Models\EventAttendee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductQuestionAnswer;
use App\Models\Student;
use App\Models\User;
use App\Support\LocationNameGuidance;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

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
    Mail::fake();
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
    $teacher = User::factory()->isTeacher()->create(['email' => 'teacher@example.com']);
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
        ->and($event->orderItemFulfillments()->sole()->students->modelKeys())->toBe([$student->id])
        ->and($event->attendees()->whereMorphedTo('attendee', $student)->exists())->toBeTrue()
        ->and($event->attendees()->whereMorphedTo('attendee', $order->user)->exists())->toBeFalse();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'order-fulfillment-scheduled'
        && $mail->hasTo($order->user->email));
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'order-fulfillment-scheduled'
        && $mail->hasTo('teacher@example.com'));
});

it('reopens scheduled fulfillment and explains that the reason is user visible', function (): void {
    $order = Order::factory()->completed()->create();
    $student = Student::factory()->create(['user_id' => $order->user_id]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'question' => 'Lesson focus',
        'answer' => 'Turns',
    ]);
    $event = Event::factory()->standalone()->create([
        'name' => 'Avery Private Lesson (MAIN CAMPUS)',
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    $teacher = User::factory()->isTeacher()->create(['email' => 'teacher@example.com']);
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [$teacher->id]);
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    $fulfillment = app(RecordOrderItemFulfillment::class)->handle(
        orderItem: $orderItem,
        unitNumbers: [1],
        fulfilledBy: $actor,
        source: $event,
        studentIds: [$student->id],
    )->sole();

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->mountAction(TestAction::make('reopenFulfillment')->table($orderItem))
        ->assertSchemaComponentExists(
            'reason',
            'mountedActionSchema0',
            fn (Textarea $textarea): bool => str_contains(
                (string) $textarea->getChildSchema(Textarea::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString(),
                'Reason is visible to user / parent.',
            ),
        );

    EventAttendee::factory()->forStudent($student)->create(['event_id' => $event->id]);
    Mail::fake();

    livewire(OrderFulfillment::class)
        ->loadTable()
        ->callAction(TestAction::make('reopenFulfillment')->table($orderItem), [
            'fulfillment_ids' => [$fulfillment->id],
            'reason' => 'Teacher conflict; EAC will contact you with options.',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Fulfillment reopened');

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::Pending)
        ->and($fulfillment->refresh()->void_reason)->toBe('Teacher conflict; EAC will contact you with options.')
        ->and($event->attendees()->whereMorphedTo('attendee', $student)->exists())->toBeFalse()
        ->and($event->refresh()->isCancelled())->toBeTrue()
        ->and($event->cancellation_reason)->toBe('Teacher conflict; EAC will contact you with options.');

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'order-fulfillment-reopened'
        && $mail->hasTo($order->user->email)
        && str_contains($mail->getRenderedEmail()->html, 'Teacher conflict; EAC will contact you with options.'));
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'order-fulfillment-reopened'
        && $mail->hasTo('teacher@example.com'));
    Mail::assertNotQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-cancellation');
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
        ->assertSchemaComponentExists(
            'name',
            'mountedActionSchema0',
            fn (TextInput $input): bool => str_contains(
                (string) $input->getChildSchema(TextInput::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString(),
                LocationNameGuidance::HELP_TEXT,
            ),
        )
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
