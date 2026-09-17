<?php

declare(strict_types=1);

use App\Actions\TextMessages\DeliverTextMessage;
use App\Actions\TextMessages\QueueEventTextMessages;
use App\Contracts\TextMessageTransport;
use App\Data\TextMessages\TextMessageSubmission;
use App\Enums\TextMessageStatus;
use App\Jobs\SendTextMessage;
use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\TextMessageBatch;
use App\Models\TextMessageRecipient;
use App\Models\User;
use App\Services\TextMessages\TextMessageReview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set([
        'text-messages.enabled' => true,
        'text-messages.driver' => 'fake',
        'text-messages.drivers.fake' => TextMessageTransport::class,
        'text-messages.sender' => '+13135550100',
    ]);
    $this->transport = Mockery::mock(TextMessageTransport::class);
    $this->transport->shouldReceive('validateConfiguration')->byDefault();
    app()->instance(TextMessageTransport::class, $this->transport);
    Queue::fake();
    $this->event = Event::factory()->create();
    $this->student = Student::factory()->create();
    $this->enrollment = Enrollment::factory()->withStudent($this->student)->create(['course_id' => $this->event->course_id]);
    $this->contact = EmergencyContact::factory()->forStudent($this->student)->create(['phone_number' => '(313) 555-0123', 'wants_text_updates' => true]);
    $this->review = app(TextMessageReview::class)->prepare(auth()->user(), [$this->event->id], 'EAC: Class is cancelled.', (string) Str::uuid());
});

it('persists a batch once across repeated confirmation and enforces phone uniqueness', function (): void {
    $action = app(QueueEventTextMessages::class);
    $first = $action->handle(auth()->user(), $this->review);
    $second = $action->handle(auth()->user(), $this->review);
    expect($first->id)->toBe($second->id)
        ->and(TextMessageBatch::count())->toBe(1)
        ->and($first->recipients()->count())->toBe(1)
        ->and($this->event->refresh()->cancelled_at)->toBeNull();
    expect(fn () => $first->recipients()->create(['phone' => '+13135550123', 'sources' => []]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows a separate intentional send of the same text', function (): void {
    app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    $review = app(TextMessageReview::class)->prepare(auth()->user(), [$this->event->id], 'EAC: Class is cancelled.', (string) Str::uuid());
    app(QueueEventTextMessages::class)->handle(auth()->user(), $review);
    expect(TextMessageBatch::count())->toBe(2)->and(TextMessageRecipient::count())->toBe(2);
});

it('requires another review when recipients change and prevents token tampering', function (): void {
    $this->contact->update(['phone_number' => '(313) 555-0999']);
    expect(fn () => app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review))->toThrow(ValidationException::class);
    expect(fn () => app(QueueEventTextMessages::class)->handle(auth()->user(), 'forged'))->toThrow(ValidationException::class);
    expect(TextMessageBatch::count())->toBe(0);
});

it('binds a review to its author', function (): void {
    $other = User::factory()->isOwner()->create();
    expect(fn () => app(QueueEventTextMessages::class)->handle($other, $this->review))->toThrow(ValidationException::class);
});

it('dispatches jobs only after commit and recovers missed dispatches', function (): void {
    Bus::fake([SendTextMessage::class]);
    DB::beginTransaction();
    $batch = app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    Bus::assertNotDispatched(SendTextMessage::class);
    DB::commit();
    Bus::assertDispatched(SendTextMessage::class, fn (SendTextMessage $job): bool => $job->recipientId === $batch->recipients()->first()->id);
    Bus::fake([SendTextMessage::class]);
    $this->artisan('text-messages:recover')->assertSuccessful();
    Bus::assertDispatched(SendTextMessage::class);
});

it('sends once when duplicate or concurrent jobs process the same number', function (): void {
    $batch = app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    $recipient = $batch->recipients()->first();
    $this->transport->shouldReceive('send')->once()->with($recipient->phone, $batch->body, $batch->sender, $recipient->id)
        ->andReturnUsing(function () use ($recipient): TextMessageSubmission {
            app(DeliverTextMessage::class)->handle($recipient->id);

            return new TextMessageSubmission(TextMessageStatus::Accepted, ['id' => 42]);
        });
    app(DeliverTextMessage::class)->handle($recipient->id);
    app(DeliverTextMessage::class)->handle($recipient->id);
    expect($recipient->refresh()->status)->toBe(TextMessageStatus::Accepted)
        ->and($recipient->attempts)->toBe(1)->and($recipient->provider_receipt)->toBe(['id' => 42]);
});

it('rechecks opt-in and enrollment before delivery', function (string $change): void {
    $batch = app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    $recipient = $batch->recipients()->first();

    if ($change === 'consent') {
        $this->contact->update(['wants_text_updates' => false]);
    } else {
        $this->enrollment->delete();
    }

    $this->transport->shouldNotReceive('send');
    app(DeliverTextMessage::class)->handle($recipient->id);
    expect($recipient->refresh()->status)->toBe(TextMessageStatus::Skipped);
})->with(['consent', 'enrollment']);

it('preserves the chosen provider and sender for a queued batch', function (): void {
    $batch = app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    config()->set('text-messages.driver', 'different-provider');
    config()->set('text-messages.sender', 'different-sender');
    $recipient = $batch->recipients()->first();
    $this->transport->shouldReceive('send')->once()->with($recipient->phone, $batch->body, '+13135550100', $recipient->id)
        ->andReturn(new TextMessageSubmission(TextMessageStatus::Accepted));
    app(DeliverTextMessage::class)->handle($recipient->id);
    expect($recipient->refresh()->status)->toBe(TextMessageStatus::Accepted);
});

it('flags ambiguous failures without resending and recovers interrupted jobs', function (): void {
    $batch = app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    $recipient = $batch->recipients()->first();
    $this->transport->shouldReceive('send')->once()->andThrow(new RuntimeException('timeout'));
    app(DeliverTextMessage::class)->handle($recipient->id);
    app(DeliverTextMessage::class)->handle($recipient->id);
    expect($recipient->refresh()->status)->toBe(TextMessageStatus::Unknown);
    Queue::fake();
    $interrupted = TextMessageRecipient::factory()->create(['status' => TextMessageStatus::Sending, 'started_at' => now()->subMinutes(3)]);
    $active = TextMessageRecipient::factory()->create(['status' => TextMessageStatus::Sending, 'started_at' => now()]);
    $this->artisan('text-messages:recover')->assertSuccessful();
    expect($interrupted->refresh()->status)->toBe(TextMessageStatus::Unknown)
        ->and($active->refresh()->status)->toBe(TextMessageStatus::Sending);
    Queue::assertNotPushed(SendTextMessage::class, fn (SendTextMessage $job): bool => in_array($job->recipientId, [$recipient->id, $interrupted->id, $active->id]));
});

it('bounds rate-limit retries and waits until each attempt is due', function (): void {
    $recipient = app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review)->recipients()->first();
    $this->transport->shouldReceive('send')->times(3)->andReturn(new TextMessageSubmission(TextMessageStatus::Pending, retryAfter: 60));
    $delivery = app(DeliverTextMessage::class);
    $delivery->handle($recipient->id);
    $delivery->handle($recipient->id);
    expect($recipient->refresh()->attempts)->toBe(1)->and($recipient->status)->toBe(TextMessageStatus::Pending);
    $this->travel(61)->seconds();
    $delivery->handle($recipient->id);
    $this->travel(121)->seconds();
    $delivery->handle($recipient->id);
    $delivery->handle($recipient->id);
    expect($recipient->refresh()->attempts)->toBe(3)->and($recipient->status)->toBe(TextMessageStatus::Failed);
});

it('continues other recipients when one submission fails', function (): void {
    EmergencyContact::factory()->for($this->contact->studentWaiver)->create(['phone_number' => '(313) 555-0999', 'wants_text_updates' => true]);
    $review = app(TextMessageReview::class)->prepare(auth()->user(), [$this->event->id], 'Notice', (string) Str::uuid());
    $recipients = app(QueueEventTextMessages::class)->handle(auth()->user(), $review)->recipients;
    $this->transport->shouldReceive('send')->twice()->andReturn(new TextMessageSubmission(TextMessageStatus::Failed), new TextMessageSubmission(TextMessageStatus::Accepted));
    foreach ($recipients as $recipient) {
        app(DeliverTextMessage::class)->handle($recipient->id);
    }
    expect($recipients[0]->refresh()->status)->toBe(TextMessageStatus::Failed)
        ->and($recipients[1]->refresh()->status)->toBe(TextMessageStatus::Accepted);
});

it('blocks new sends and pauses pending jobs when texting is disabled', function (): void {
    $recipient = app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review)->recipients()->first();
    config()->set('text-messages.enabled', false);
    $this->transport->shouldNotReceive('send');
    app(DeliverTextMessage::class)->handle($recipient->id);
    expect($recipient->refresh()->status)->toBe(TextMessageStatus::Pending);
    expect(fn () => app(TextMessageReview::class)->prepare(auth()->user(), [$this->event->id], 'Notice', (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('revalidates event permissions after review and denies access revoked before confirmation', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $teacher->givePermissionTo('Send:TextMessage');
    $this->event->teachers()->sync([$teacher->id]);
    $review = app(TextMessageReview::class)->prepare($teacher, [$this->event->id], 'Notice', (string) Str::uuid());
    $this->event->teachers()->detach($teacher);
    expect(fn () => app(QueueEventTextMessages::class)->handle($teacher, $review))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect(TextMessageBatch::count())->toBe(0);
});

it('does not reuse one submission token for a different message', function (): void {
    $review = app(TextMessageReview::class)->read(auth()->user(), $this->review);
    app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    $changed = app(TextMessageReview::class)->prepare(auth()->user(), [$this->event->id], 'Different message', $review['submission_token']);
    expect(fn () => app(QueueEventTextMessages::class)->handle(auth()->user(), $changed))->toThrow(ValidationException::class);
    expect(TextMessageBatch::count())->toBe(1);
});

it('keeps rolled-back sends out of the queue and database', function (): void {
    Bus::fake([SendTextMessage::class]);
    DB::beginTransaction();
    app(QueueEventTextMessages::class)->handle(auth()->user(), $this->review);
    DB::rollBack();
    Bus::assertNotDispatched(SendTextMessage::class);
    expect(TextMessageBatch::count())->toBe(0);
});
