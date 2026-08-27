<?php

declare(strict_types=1);

use App\Actions\Events\CancelEvent;
use App\Actions\Store\RecordOrderItemFulfillment;
use App\Actions\Store\VoidOrderItemFulfillment;
use App\Enums\FulfillmentWorkflow;
use App\Enums\OrderItemStatus;
use App\Models\Course;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('records partial and complete manual fulfillment by purchased unit', function (): void {
    $user = User::factory()->create();
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 2,
        'status' => OrderItemStatus::Pending,
        'fulfillment_workflow' => FulfillmentWorkflow::Manual,
    ]);

    app(RecordOrderItemFulfillment::class)->handle($orderItem, [1], $user, note: 'Front desk pickup.');

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::PartiallyFulfilled)
        ->and($orderItem->fulfilledQuantity())->toBe(1)
        ->and($orderItem->remainingUnitNumbers())->toBe([2])
        ->and($orderItem->fulfillments()->firstOrFail()->note)->toBe('Front desk pickup.');

    app(RecordOrderItemFulfillment::class)->handle($orderItem, [2], $user);

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::Fulfilled)
        ->and($orderItem->fulfilledQuantity())->toBe(2)
        ->and($orderItem->remainingUnitNumbers())->toBe([]);
});

it('prevents the same unit from being fulfilled twice', function (): void {
    $user = User::factory()->create();
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 2,
        'fulfillment_workflow' => FulfillmentWorkflow::Manual,
    ]);
    $action = app(RecordOrderItemFulfillment::class);

    $action->handle($orderItem, [1], $user);
    $action->handle($orderItem, [1], $user);
})->throws(DomainException::class, 'One or more selected units are already fulfilled or do not exist.');

it('allows one future standalone event to fulfill units from multiple orders', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->standalone()->create([
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $firstItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'product_id' => $product->id,
        'quantity' => 2,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $secondItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'product_id' => $product->id,
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $action = app(RecordOrderItemFulfillment::class);

    $action->handle($firstItem, [1, 2], $user, $event);
    $action->handle($secondItem, [1], $user, $event);

    expect($event->orderItemFulfillments()->count())->toBe(3)
        ->and($event->orderItemFulfillments()->distinct('order_item_id')->count('order_item_id'))->toBe(2)
        ->and($firstItem->refresh()->status)->toBe(OrderItemStatus::Fulfilled)
        ->and($secondItem->refresh()->status)->toBe(OrderItemStatus::Fulfilled);
});

it('rejects a past event for scheduled fulfillment', function (): void {
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);

    app(RecordOrderItemFulfillment::class)->handle($orderItem, [1], User::factory()->create(), $event);
})->throws(DomainException::class, 'Only future events may fulfill this item.');

it('rejects cancelled and course events for scheduled fulfillment', function (Event $event): void {
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);

    app(RecordOrderItemFulfillment::class)->handle($orderItem, [1], User::factory()->create(), $event);
})->with([
    'cancelled event' => fn (): Event => Event::factory()->standalone()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
        'cancelled_at' => now(),
    ]),
    'course event' => fn (): Event => Event::factory()->create([
        'course_id' => Course::factory(),
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]),
])->throws(DomainException::class, 'Only active standalone events may fulfill this item.');

it('voids fulfillment with audit history and reopens the unit', function (): void {
    $fulfilledBy = User::factory()->create();
    $voidedBy = User::factory()->create();
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::Manual,
    ]);
    $fulfillment = app(RecordOrderItemFulfillment::class)
        ->handle($orderItem, [1], $fulfilledBy)
        ->firstOrFail();

    app(VoidOrderItemFulfillment::class)->handle($fulfillment, $voidedBy, 'Wrong item selected.');

    expect($fulfillment->refresh()->voided_by_user_id)->toBe($voidedBy->id)
        ->and($fulfillment->void_reason)->toBe('Wrong item selected.')
        ->and($fulfillment->voided_at)->not->toBeNull()
        ->and($orderItem->refresh()->status)->toBe(OrderItemStatus::Pending)
        ->and($orderItem->remainingUnitNumbers())->toBe([1]);
});

it('voids every linked unit when an event source is reopened', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $items = collect([1, 2])->map(fn (): OrderItem => OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]));

    foreach ($items as $item) {
        app(RecordOrderItemFulfillment::class)->handle($item, [1], $user, $event);
    }

    $count = app(VoidOrderItemFulfillment::class)->forSource($event, $user, 'Event cancelled.');

    expect($count)->toBe(2)
        ->and($items->every(fn (OrderItem $item): bool => $item->refresh()->status === OrderItemStatus::Pending))->toBeTrue()
        ->and($event->orderItemFulfillments()->whereNull('voided_at')->count())->toBe(0);
});

it('reopens linked fulfillment when its event is cancelled', function (): void {
    Mail::fake();
    $actor = auth()->user();
    expect($actor)->toBeInstanceOf(User::class);
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    app(RecordOrderItemFulfillment::class)->handle($orderItem, [1], $actor, $event);

    app(CancelEvent::class)->handle($event, $actor, 'Purchaser needs to reschedule.', false);

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::Pending)
        ->and($orderItem->activeFulfillments()->count())->toBe(0)
        ->and($orderItem->fulfillments()->firstOrFail()->void_reason)
        ->toBe('Event cancelled: Purchaser needs to reschedule.');
});

it('reopens linked fulfillment before its event is deleted', function (): void {
    $actor = auth()->user();
    expect($actor)->toBeInstanceOf(User::class);
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed(),
        'quantity' => 1,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    app(RecordOrderItemFulfillment::class)->handle($orderItem, [1], $actor, $event);

    $event->delete();

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::Pending)
        ->and($orderItem->activeFulfillments()->count())->toBe(0)
        ->and($orderItem->fulfillments()->firstOrFail()->void_reason)
        ->toBe('The linked event was deleted.');
});

it('keeps legacy fulfilled items fulfilled without creating audit records', function (): void {
    $orderItem = OrderItem::factory()->fulfilled()->create([
        'quantity' => 3,
        'fulfillment_workflow' => FulfillmentWorkflow::Manual,
    ]);

    expect($orderItem->fulfilledQuantity())->toBe(3)
        ->and($orderItem->remainingQuantity())->toBe(0)
        ->and($orderItem->fulfillments()->count())->toBe(0);
});
