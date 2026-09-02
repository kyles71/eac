<?php

declare(strict_types=1);

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Actions\Mail\SendOrderFulfillmentEmail;
use App\Actions\Store\RecordOrderItemFulfillment;
use App\Enums\FulfillmentWorkflow;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestionAnswer;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;

it('registers customizable scheduled and reopened fulfillment emails', function (): void {
    $registry = app(EmailTypeRegistry::class);
    $scheduled = $registry->get('order-fulfillment-scheduled');
    $reopened = $registry->get('order-fulfillment-reopened');

    expect($scheduled->category)->toBe('transactional')
        ->and(array_keys($scheduled->tokensByKey()))->toContain(
            'event.name',
            'event.date',
            'event.start_time',
            'event.end_time',
            'event.teachers',
        )
        ->and(array_keys($scheduled->slotsByMergeTag()))->toBe(['slot.order-fulfillment-details'])
        ->and(array_keys($reopened->tokensByKey()))->toContain('fulfillment.reason')
        ->and(array_keys($reopened->slotsByMergeTag()))->toBe(['slot.order-fulfillment-details']);
});

it('emails linked student addresses and every event teacher with event and unit details', function (): void {
    Mail::fake();
    $now = CarbonImmutable::parse('2026-09-01 09:00:00', 'America/New_York');
    $this->travelTo($now->utc());

    $guardian = User::factory()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Dancer',
        'email' => 'guardian@example.com',
    ]);
    $student = Student::factory()->for($guardian)->create([
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
    ]);
    StudentEmail::factory()->for($student)->create(['email' => 'avery@example.com']);
    $unrelatedGuardian = User::factory()->create(['email' => 'unrelated@example.com']);
    $unrelatedStudent = Student::factory()->for($unrelatedGuardian)->create();

    $firstTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Jordan',
        'last_name' => 'Teacher',
        'email' => 'jordan@example.com',
    ]);
    $secondTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Taylor',
        'last_name' => 'Teacher',
        'email' => 'taylor@example.com',
    ]);
    $unassignedTeacher = User::factory()->isTeacher()->create(['email' => 'unassigned@example.com']);

    $product = Product::factory()->standalone()->create([
        'name' => 'Private Coaching',
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed()->create(['user_id' => $guardian->id]),
        'product_id' => $product->id,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'unit_number' => 1,
        'question' => 'Lesson <focus>',
        'answer' => 'Turns & leaps',
    ]);
    $event = Event::factory()->standalone()->create([
        'name' => 'Avery Private Lesson (MAIN CAMPUS)',
        'start_time' => $now->addWeeks(2)->setTime(16, 0)->utc(),
        'end_time' => $now->addWeeks(2)->setTime(17, 15)->utc(),
    ]);
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [
        $firstTeacher->id,
        $secondTeacher->id,
    ]);
    $fulfillments = app(RecordOrderItemFulfillment::class)->handle(
        orderItem: $orderItem,
        unitNumbers: [1],
        fulfilledBy: auth()->user(),
        source: $event,
        studentIds: [$student->id],
    );

    expect($fulfillments->sole()->students->modelKeys())->toBe([$student->id])
        ->and(app(SendOrderFulfillmentEmail::class)->scheduled($event, $fulfillments))->toBe(4);

    foreach (['guardian@example.com', 'avery@example.com', 'jordan@example.com', 'taylor@example.com'] as $recipient) {
        Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'order-fulfillment-scheduled'
            && $mail->hasTo($recipient)
            && $mail->usesMailer('transactional'));
    }

    Mail::assertNotQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->hasTo('unrelated@example.com')
        || $mail->hasTo('unassigned@example.com'));
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        if ($mail->emailTypeKey !== 'order-fulfillment-scheduled' || ! $mail->hasTo('guardian@example.com')) {
            return false;
        }

        $rendered = $mail->getRenderedEmail();

        return $rendered->subject === 'Scheduled: Avery Private Lesson (MAIN CAMPUS) on September 15, 2026'
            && str_contains($rendered->html, 'September 15, 2026')
            && str_contains($rendered->html, '4:00 PM EDT')
            && str_contains($rendered->html, '5:15 PM EDT')
            && str_contains($rendered->html, 'Jordan Teacher, Taylor Teacher')
            && str_contains($rendered->html, 'Avery Dancer')
            && str_contains($rendered->html, 'Lesson &lt;focus&gt;')
            && str_contains($rendered->html, 'Turns &amp; leaps');
    });

    expect($unrelatedStudent->exists)->toBeTrue()
        ->and($unassignedTeacher->exists)->toBeTrue();
});

it('includes the original event and user-visible reason in reopened fulfillment emails', function (): void {
    Mail::fake();
    $guardian = User::factory()->create(['email' => 'guardian@example.com']);
    $student = Student::factory()->for($guardian)->create();
    $teacher = User::factory()->isTeacher()->create(['email' => 'teacher@example.com']);
    $orderItem = OrderItem::factory()->create([
        'order_id' => Order::factory()->completed()->create(['user_id' => $guardian->id]),
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'question' => 'Requested skill',
        'answer' => 'Pirouettes',
    ]);
    $event = Event::factory()->standalone()->create([
        'name' => 'Original Private Lesson (STUDIO B)',
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [$teacher->id]);
    $fulfillments = app(RecordOrderItemFulfillment::class)->handle(
        orderItem: $orderItem,
        unitNumbers: [1],
        fulfilledBy: auth()->user(),
        source: $event,
        studentIds: [$student->id],
    );
    $reason = 'Teacher unavailable <today>; EAC will contact you with options.';

    expect(app(SendOrderFulfillmentEmail::class)->reopened($event, $fulfillments, $reason))->toBe(2);

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'order-fulfillment-reopened'
            && $mail->hasTo('guardian@example.com')
            && str_contains($rendered->html, 'Original Private Lesson (STUDIO B)')
            && str_contains($rendered->html, 'Requested skill')
            && str_contains($rendered->html, 'Pirouettes')
            && str_contains($rendered->html, 'Teacher unavailable &lt;today&gt;; EAC will contact you with options.');
    });
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'order-fulfillment-reopened'
        && $mail->hasTo('teacher@example.com'));
});
