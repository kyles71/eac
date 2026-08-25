<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\EventSubstituteRequestStatus;
use App\Filament\Admin\Pages\SubstituteEventDetails;
use App\Filament\Admin\Pages\SubstituteRequest;
use App\Filament\Admin\Widgets\SubstituteRequestBanners;
use App\Filament\Tables\Columns\AttendanceRadioColumn;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventSubstituteRequest;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('shows a global accept and decline banner in the admin panel only', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $request = EventSubstituteRequest::factory()->create([
        'event_id' => adminSubstituteEvent()->id,
        'teacher_id' => $teacher->id,
    ]);
    $this->actingAs($teacher);

    livewire(SubstituteRequestBanners::class)
        ->assertSee('Substitute Request')
        ->assertSee($request->event->name)
        ->assertSee('Accept')
        ->assertSee('Decline');

    $this->get('/admin')
        ->assertOk()
        ->assertSeeHtml('data-substitute-request-banner="'.$request->id.'"')
        ->assertSeeText('Substitute Request')
        ->assertSeeText($request->event->name);

    $this->get('/dancefam')
        ->assertOk()
        ->assertDontSeeHtml('data-substitute-request-banner="'.$request->id.'"');
});

it('suppresses the current request banner on its review page while retaining other request banners', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $currentRequest = EventSubstituteRequest::factory()->create([
        'event_id' => adminSubstituteEvent(['name' => 'Current Request Event'])->id,
        'teacher_id' => $teacher->id,
    ]);
    $otherRequest = EventSubstituteRequest::factory()->create([
        'event_id' => adminSubstituteEvent(['name' => 'Other Request Event'])->id,
        'teacher_id' => $teacher->id,
    ]);

    $this->actingAs($teacher)
        ->get(SubstituteRequest::getUrl(['request' => $currentRequest], panel: 'admin'))
        ->assertOk()
        ->assertDontSeeHtml('data-substitute-request-banner="'.$currentRequest->id.'"')
        ->assertSeeHtml('data-substitute-request-banner="'.$otherRequest->id.'"');
});

it('lets only the requested teacher review a pending summary without protected details', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $otherTeacher = User::factory()->isTeacher()->create();
    $event = adminSubstituteEvent([
        'description' => 'Public event summary',
        'details' => 'Private lesson plan',
    ]);
    $request = EventSubstituteRequest::factory()->create([
        'event_id' => $event->id,
        'teacher_id' => $teacher->id,
    ]);

    $this->actingAs($teacher)
        ->get(SubstituteRequest::getUrl(['request' => $request], panel: 'admin'))
        ->assertOk()
        ->assertSeeText('Public event summary')
        ->assertDontSeeText('Private lesson plan');

    $this->actingAs($otherTeacher)
        ->get(SubstituteRequest::getUrl(['request' => $request], panel: 'admin'))
        ->assertForbidden();
});

it('accepts a request from the review page', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $event = adminSubstituteEvent();
    $request = EventSubstituteRequest::factory()->create([
        'event_id' => $event->id,
        'teacher_id' => $teacher->id,
    ]);
    $this->actingAs($teacher);

    livewire(SubstituteRequest::class, ['request' => $request])
        ->callAction('accept')
        ->assertNotified('Substitute request accepted');

    expect($request->refresh()->status)->toBe(EventSubstituteRequestStatus::Accepted)
        ->and($event->refresh()->substitute_teacher_id)->toBe($teacher->id);
});

it('shows lesson plan roster and editable attendance only to the confirmed substitute', function (): void {
    $course = Course::factory()->create();
    $student = Student::factory()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Dancer',
    ]);
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = adminSubstituteEvent([
        'course_id' => $course->id,
        'details' => 'Practice the recital finale.',
    ]);
    $teacher = User::factory()->isTeacher()->create();
    confirmedSubstituteRequest($event, $teacher);
    $this->actingAs($teacher);

    livewire(SubstituteEventDetails::class, ['event' => $event])
        ->loadTable()
        ->assertSee('Practice the recital finale.')
        ->assertCanSeeTableRecords([$enrollment])
        ->assertTableColumnExists(
            'attendance_status',
            fn (AttendanceRadioColumn $column): bool => ! $column->isDisabled(),
            $enrollment,
        )
        ->assertSee('Present')
        ->assertSee('Late')
        ->call(
            'updateTableColumnState',
            'attendance_status',
            (string) $enrollment->id,
            AttendanceStatus::Late->value,
        )
        ->call('updateTableColumnState', 'notes', (string) $enrollment->id, 'Arrived late')
        ->assertHasNoErrors();

    expect(EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->where('status', AttendanceStatus::Late->value)
        ->where('notes', 'Arrived late')
        ->exists())->toBeTrue();

    $this->actingAs(User::factory()->isTeacher()->create())
        ->get(SubstituteEventDetails::getUrl(['event' => $event], panel: 'admin'))
        ->assertForbidden();
});

it('lets a confirmed substitute request release while retaining the assignment', function (): void {
    $event = adminSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create();
    $request = confirmedSubstituteRequest($event, $teacher);
    $this->actingAs($teacher);

    livewire(SubstituteEventDetails::class, ['event' => $event])
        ->callAction(TestAction::make('requestRelease'), ['reason' => 'Family emergency'])
        ->assertNotified('Release requested');

    expect($request->refresh()->release_reason)->toBe('Family emergency')
        ->and($event->refresh()->substitute_teacher_id)->toBe($teacher->id);
});

/** @param array<string, mixed> $attributes */
function adminSubstituteEvent(array $attributes = []): Event
{
    return Event::factory()->create([
        'course_id' => null,
        'name' => 'Substitute Event',
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
        ...$attributes,
    ]);
}

function confirmedSubstituteRequest(Event $event, User $teacher): EventSubstituteRequest
{
    $request = EventSubstituteRequest::factory()->accepted()->create([
        'event_id' => $event->id,
        'teacher_id' => $teacher->id,
        'response_recorded_by_user_id' => $teacher->id,
    ]);
    $event->update([
        'substitute_teacher_id' => $teacher->id,
        'substitute_needed_at' => now(),
    ]);

    return $request;
}
