<?php

declare(strict_types=1);

use App\Contracts\TextMessageTransport;
use App\Filament\Admin\Pages\TextMessages;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\TextMessageBatch;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Queue::fake();
    config()->set([
        'text-messages.enabled' => true,
        'text-messages.driver' => 'fake',
        'text-messages.drivers.fake' => TextMessageTransport::class,
        'text-messages.sender' => '+13135550100',
    ]);
    $transport = Mockery::mock(TextMessageTransport::class);
    $transport->shouldReceive('validateConfiguration')->byDefault();
    app()->instance(TextMessageTransport::class, $transport);
    $this->event = Event::factory()->create(['start_time' => now()->addHour()]);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create(['course_id' => $this->event->course_id]);
    $this->contact = EmergencyContact::factory()->forStudent($student)->create(['phone_number' => '(313) 555-0123', 'wants_text_updates' => true]);
});

it('reviews and sends a single event without changing cancellation status', function (): void {
    livewire(ViewEvent::class, ['record' => $this->event->id])
        ->mountAction('sendTextMessage')
        ->fillForm(['body' => 'EAC: Class cancelled today.'])
        ->goToNextWizardStep()
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('+13135550123')
        ->assertMountedActionModalSee('EAC: Class cancelled today.')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Text messages queued');
    expect(TextMessageBatch::count())->toBe(1)
        ->and(TextMessageBatch::first()->body)->toBe('EAC: Class cancelled today.')
        ->and($this->event->refresh()->cancelled_at)->toBeNull();
});

it('reviews selected events by date including cancelled events and excludes deselected events', function (): void {
    $other = Event::factory()->create(['start_time' => $this->event->start_time, 'cancelled_at' => now()]);
    $local = $this->event->start_time->copy()->timezone(config('app.display_timezone'));
    livewire(ListEvents::class)
        ->mountAction('textEventsByDate')
        ->fillForm(['date' => $local->format('Y-m-d'), 'time' => '00:00', 'event_ids' => [$this->event->id, $other->id], 'body' => 'EAC: Weather cancellation.'])
        ->goToNextWizardStep()->assertHasNoActionErrors()
        ->assertMountedActionModalSee('Cancelled')
        ->goToPreviousWizardStep()
        ->fillForm(['event_ids' => [$this->event->id]])
        ->goToNextWizardStep()->callMountedAction()->assertHasNoActionErrors();
    expect(array_column(TextMessageBatch::first()->events, 'id'))->toBe([$this->event->id]);
});

it('does not permit bypassing review or sending with no opted-in contacts', function (): void {
    livewire(ViewEvent::class, ['record' => $this->event->id])
        ->mountAction('sendTextMessage')->fillForm(['body' => 'Notice'])->callMountedAction()
        ->assertHasActionErrors();
    $this->contact->update(['wants_text_updates' => false]);
    livewire(ViewEvent::class, ['record' => $this->event->id])
        ->mountAction('sendTextMessage')->fillForm(['body' => 'Notice'])->goToNextWizardStep()
        ->assertHasActionErrors();
    expect(TextMessageBatch::count())->toBe(0);
});

it('shows history to owners and denies teachers even if they can send email', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();
    $batch = TextMessageBatch::factory()->create();
    $this->actingAs($owner);
    expect($owner->can('Send:TextMessage'))->toBeTrue();
    livewire(TextMessages::class)->loadTable()->assertCanSeeTableRecords([$batch])
        ->mountAction(TestAction::make('viewTextMessage')->table($batch))
        ->assertMountedActionModalSee($batch->body);
    $this->actingAs($teacher);
    expect($teacher->can('Send:Email'))->toBeTrue()
        ->and($teacher->can('Send:TextMessage'))->toBeFalse()
        ->and($teacher->can('View:TextMessageHistory'))->toBeFalse();
    $this->get(TextMessages::getUrl())->assertForbidden();
    livewire(ListEvents::class)->assertActionHidden('textEventsByDate');
});

it('rejects changes after review and keeps the send history empty', function (): void {
    $component = livewire(ViewEvent::class, ['record' => $this->event->id])
        ->mountAction('sendTextMessage')->fillForm(['body' => 'Notice'])->goToNextWizardStep();
    $this->contact->update(['wants_text_updates' => false]);
    $component->callMountedAction()->assertHasActionErrors();
    expect(TextMessageBatch::count())->toBe(0);
});

it('supports the event row action and renders the actual preview safely', function (): void {
    $this->contact->update(['name' => '<script>alert(1)</script>']);
    livewire(ListEvents::class)->loadTable()
        ->mountAction(TestAction::make('sendTextMessage')->table($this->event))
        ->fillForm(['body' => 'EAC: <b>Classes cancelled</b>'])
        ->goToNextWizardStep()->assertHasNoActionErrors()
        ->assertMountedActionModalSee('<script>alert(1)</script>')
        ->assertMountedActionModalDontSeeHtml('<script>alert(1)</script>')
        ->assertMountedActionModalSee('EAC: <b>Classes cancelled</b>')
        ->callMountedAction()->assertHasNoActionErrors();
    expect(TextMessageBatch::count())->toBe(1);
});

it('requires a new review after the draft message is edited', function (): void {
    livewire(ViewEvent::class, ['record' => $this->event->id])
        ->mountAction('sendTextMessage')->fillForm(['body' => 'Original notice'])
        ->goToNextWizardStep()->assertHasNoActionErrors()
        ->fillForm(['body' => 'Changed notice'])->callMountedAction()->assertHasActionErrors()
        ->assertNotified('Review the text message');
    expect(TextMessageBatch::count())->toBe(0);
});

it('rejects a forged day selection outside the chosen cutoff', function (): void {
    $local = $this->event->start_time->copy()->timezone(config('app.display_timezone'));
    livewire(ListEvents::class)->mountAction('textEventsByDate')
        ->fillForm(['date' => $local->subDay()->format('Y-m-d'), 'time' => '00:00', 'event_ids' => [$this->event->id], 'body' => 'Notice'])
        ->goToNextWizardStep()->assertHasActionErrors();
    expect(TextMessageBatch::count())->toBe(0);
});
