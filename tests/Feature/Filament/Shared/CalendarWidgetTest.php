<?php

declare(strict_types=1);

use App\Enums\CalendarAccess;
use App\Filament\Admin\Resources\Calendars\Pages\ListCalendars;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Shared\Schemas\PeopleAndGroupsPicker;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Models\Calendar;
use App\Models\CalendarAudience;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Saade\FilamentFullCalendar\Actions\CreateAction as CalendarCreateAction;
use Spatie\Tags\Tag;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    foreach (Calendar::systemCalendarDefinitions() as $slug => $calendar) {
        Calendar::query()->updateOrCreate(['slug' => $slug], $calendar);
    }

    Tag::findOrCreate(Calendar::SLUG_EAC, Course::CALENDAR_TAG_TYPE);
    Tag::findOrCreate(Calendar::SLUG_COMP, Course::CALENDAR_TAG_TYPE);

    Filament::setCurrentPanel('user');
});

it('includes course events for any assigned teacher on my calendar', function (): void {
    $teacher = User::factory()->create();
    $otherTeacher = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Ballet 1']);
    $otherCourse = Course::factory()->create(['name' => 'Ballet 2']);
    $privateCalendar = Calendar::factory()->create(['name' => 'Private Course Calendar']);
    $course->syncTagsWithType([], Course::CALENDAR_TAG_TYPE);
    $otherCourse->syncTagsWithType([], Course::CALENDAR_TAG_TYPE);

    $course->teachers()->sync([$teacher->id]);
    $otherCourse->teachers()->sync([$otherTeacher->id]);

    Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $privateCalendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);
    Event::factory()->create([
        'course_id' => $otherCourse->id,
        'calendar_id' => $privateCalendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $this->actingAs($teacher);

    $events = fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY));

    expect($events->pluck('title')->all())
        ->toContain('Ballet 1 Class')
        ->not->toContain('Ballet 2 Class');
});

it('includes enrolled student course events on my calendar without attendee records', function (): void {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $course = Course::factory()->create(['name' => 'Jazz 3']);
    $otherCourse = Course::factory()->create(['name' => 'Tap 2']);
    $privateCalendar = Calendar::factory()->create(['name' => 'Private Student Calendar']);
    $course->syncTagsWithType([], Course::CALENDAR_TAG_TYPE);
    $otherCourse->syncTagsWithType([], Course::CALENDAR_TAG_TYPE);

    Enrollment::factory()->withStudent($student)->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $privateCalendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);
    Event::factory()->create([
        'course_id' => $otherCourse->id,
        'calendar_id' => $privateCalendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $this->actingAs($user);

    $events = fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY));

    expect($events->pluck('title')->all())
        ->toContain('Jazz 3 Class')
        ->not->toContain('Tap 2 Class');
});

it('includes direct user and student invites on my calendar', function (): void {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $otherUser = User::factory()->create();
    $privateCalendar = Calendar::factory()->create(['name' => 'Private Invite Calendar']);

    $userInvite = standaloneEvent('Parent Meeting', $privateCalendar);
    $studentInvite = standaloneEvent('Student Fitting', $privateCalendar);
    $otherInvite = standaloneEvent('Other Meeting', $privateCalendar);

    EventAttendee::factory()->forUser($user)->create(['event_id' => $userInvite->id]);
    EventAttendee::factory()->forStudent($student)->create(['event_id' => $studentInvite->id]);
    EventAttendee::factory()->forUser($otherUser)->create(['event_id' => $otherInvite->id]);

    $this->actingAs($user);

    $events = fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY));

    expect($events->pluck('title')->all())
        ->toContain('Parent Meeting', 'Student Fitting')
        ->not->toContain('Other Meeting');
});

it('does not duplicate events that match my calendar in multiple ways', function (): void {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $course = Course::factory()->create(['name' => 'Acro 4']);
    $course->teachers()->sync([$user->id]);

    Enrollment::factory()->withStudent($student)->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => calendarBySlug(Calendar::SLUG_EAC)->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);
    EventAttendee::factory()->forUser($user)->create(['event_id' => $event->id]);
    EventAttendee::factory()->forStudent($student)->create(['event_id' => $event->id]);

    $this->actingAs($user);

    $events = fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY));

    expect($events->where('title', 'Acro 4 Class'))->toHaveCount(1);
});

it('includes events that overlap the requested date range', function (): void {
    $teacher = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Modern 1']);
    $course->teachers()->sync([$teacher->id]);

    Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => calendarBySlug(Calendar::SLUG_EAC)->id,
        'start_time' => Carbon::parse('2026-12-31 23:00:00'),
        'end_time' => Carbon::parse('2027-01-01 01:00:00'),
    ]);

    $this->actingAs($teacher);

    $events = fetchCalendarEvents(start: '2027-01-01T00:00:00', end: '2027-01-31T23:59:59');

    expect($events->pluck('title')->all())->toContain('Modern 1 Class');
});

it('serializes event times in the display timezone', function (): void {
    config([
        'app.timezone' => 'UTC',
        'app.display_timezone' => 'America/New_York',
    ]);

    $user = User::factory()->create();
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = Event::factory()->create([
        'name' => 'Timezone Check',
        'course_id' => null,
        'calendar_id' => $calendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00', 'UTC'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00', 'UTC'),
    ]);

    $this->actingAs($user);

    $calendarEvent = fetchCalendarEvents(
        calendar: $calendar,
        start: '2027-01-15T00:00:00-05:00',
        end: '2027-01-16T00:00:00-05:00',
    )->firstWhere('id', $event->id);

    expect($calendarEvent['start'])->toBe('2027-01-15T13:00:00-05:00')
        ->and($calendarEvent['end'])->toBe('2027-01-15T14:00:00-05:00');
});

it('uses the routed calendar color for visible course events on my calendar', function (): void {
    $user = User::factory()->create();
    assignStudentToCurrentCompetition($user);
    $course = Course::factory()->create(['name' => 'Competition Line']);
    $course->syncTagsWithType([Calendar::SLUG_COMP], Course::CALENDAR_TAG_TYPE);
    $storedCalendar = calendarBySlug(Calendar::SLUG_EAC);
    $routedCalendar = calendarBySlug(Calendar::SLUG_COMP);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $storedCalendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $this->actingAs($user);

    $calendarEvent = fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY))->firstWhere('id', $event->id);

    expect($storedCalendar->background_color)->not->toBe($routedCalendar->background_color)
        ->and($calendarEvent['backgroundColor'])->toBe($routedCalendar->background_color)
        ->and($calendarEvent['borderColor'])->toBe($routedCalendar->background_color);
});

it('hides untagged custom calendars from the widget feed', function (): void {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Private Calendar']);
    $event = standaloneEvent('Open Fundraiser', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->not->toContain($event->id);
});

it('shows public custom calendars to every authenticated user', function (): void {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->create([
        'name' => 'Community Calendar',
        'access' => CalendarAccess::Public,
    ]);
    $event = standaloneEvent('Community Open House', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('does not include eac calendar events on my calendar unless the user is directly attached', function (): void {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $publicEvent = standaloneEvent('Community Workshop', $calendar);
    $invitedEvent = standaloneEvent('Invited Workshop', $calendar);
    $course = Course::factory()->create(['name' => 'EAC Enrolled Course']);
    $course->syncTagsWithType([Calendar::SLUG_EAC], Course::CALENDAR_TAG_TYPE);
    $enrolledEvent = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    EventAttendee::factory()->forUser($user)->create(['event_id' => $invitedEvent->id]);
    Enrollment::factory()->withStudent($student)->create([
        'user_id' => $user->id,
        'course_id' => $course->id,
    ]);

    $this->actingAs($user);

    $events = fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY));

    expect($events->pluck('id')->all())
        ->not->toContain($publicEvent->id)
        ->toContain($invitedEvent->id, $enrolledEvent->id);
});

it('shows system public calendars to every authenticated user', function (): void {
    $user = User::factory()->create();
    $eacCalendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = standaloneEvent('Community Event', $eacCalendar);

    $this->actingAs($user);

    $widget = new CalendarWidget();
    $widget->mount();
    $headerActions = calendarWidgetHeaderActions($widget);
    $calendarActionGroup = collect($headerActions)->first(fn ($action): bool => $action instanceof ActionGroup);
    $calendarLabels = collect($calendarActionGroup->getFlatActions())
        ->map(fn ($action): string => $action->getLabel())
        ->all();

    expect($calendarLabels)->toContain('My Calendar', 'EAC Calendar')
        ->and(fetchCalendarEvents($eacCalendar)->pluck('id')->all())->toContain($event->id);
});

it('shows owner calendars through fixed role access', function (string $slug): void {
    $owner = User::factory()->isOwner()->create();
    $calendar = calendarBySlug($slug);
    $event = standaloneEvent($calendar->name.' Event', $calendar);

    $this->actingAs($owner);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
})->with([
    Calendar::SLUG_OWNERS,
    Calendar::SLUG_STAFF,
]);

it('grants admin panel access through admin permissions rather than calendar visibility alone', function (): void {
    $owner = User::factory()->isOwner()->create();
    $customCalendarUser = User::factory()->create();

    expect($owner->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($owner->canAccessPanel(Filament::getPanel('user')))->toBeTrue()
        ->and($customCalendarUser->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('shows staff calendar but not owners or comp calendar to teachers by default', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $staffCalendar = calendarBySlug(Calendar::SLUG_STAFF);
    $ownersCalendar = calendarBySlug(Calendar::SLUG_OWNERS);
    $compCalendar = calendarBySlug(Calendar::SLUG_COMP);
    $staffEvent = standaloneEvent('Staff Meeting', $staffCalendar);
    $ownersEvent = standaloneEvent('Owner Meeting', $ownersCalendar);
    $compEvent = standaloneEvent('Comp Planning', $compCalendar);

    $this->actingAs($teacher);

    expect(fetchCalendarEvents($staffCalendar)->pluck('id')->all())->toContain($staffEvent->id)
        ->and(fetchCalendarEvents($ownersCalendar)->pluck('id')->all())->not->toContain($ownersEvent->id)
        ->and(fetchCalendarEvents($compCalendar)->pluck('id')->all())->not->toContain($compEvent->id);
});

it('limits event calendar assignment to visible calendars without calendar policy access', function (): void {
    $teacher = User::factory()->isTeacher()->create();

    $calendarNames = assignableEventCalendarNames($teacher);

    expect($calendarNames)->toContain('EAC Calendar', 'Staff')
        ->not->toContain('My Calendar', 'Owners', 'Comp Calendar');
});

it('does not let calendar policy access override event calendar assignment access', function (): void {
    $scheduler = User::factory()->create();
    $scheduler->givePermissionTo('ViewAny:Calendar');

    $calendarNames = assignableEventCalendarNames($scheduler);

    expect($calendarNames)->toBe(['EAC Calendar']);
});

it('rejects an inaccessible calendar id when creating an event', function (): void {
    Filament::setCurrentPanel('admin');

    $scheduler = User::factory()->create();
    $scheduler->givePermissionTo(['ViewAny:Event', 'Create:Event']);
    $restrictedCalendar = Calendar::factory()->create(['name' => 'Private Calendar']);

    $this->actingAs($scheduler);

    livewire(ListEvents::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Unauthorized Event',
            'start_time' => '2027-01-15 18:00:00',
            'end_time' => '2027-01-15 19:00:00',
            'calendar_id' => $restrictedCalendar->id,
        ])
        ->assertHasActionErrors(['calendar_id']);

    expect(Event::query()->where('name', 'Unauthorized Event')->exists())->toBeFalse();
});

it('does not expand fixed system calendar access through custom audiences', function (): void {
    $advisor = User::factory()->create();
    $calendar = calendarBySlug(Calendar::SLUG_STAFF);
    $calendar->audiences()->create([
        'audience_type' => $advisor->getMorphClass(),
        'audience_id' => $advisor->id,
    ]);
    $event = standaloneEvent('Advisor Staff Event', $calendar);

    $this->actingAs($advisor);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->not->toContain($event->id);
});

it('supports restricted custom calendars with direct user grants', function (): void {
    Filament::setCurrentPanel('admin');
    $user = User::factory()->create();

    livewire(ListCalendars::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Company Calendar',
            'background_color' => '#123456',
            'access' => CalendarAccess::Restricted->value,
            'audiences_list' => [[
                'audience_type' => $user->getMorphClass(),
                'audience_id' => $user->id,
                'label' => $user->fullName,
            ]],
        ])
        ->assertNotified();

    $calendar = Calendar::query()->where('name', 'Company Calendar')->firstOrFail();
    $event = standaloneEvent('Company Planning', $calendar);

    $this->actingAs($user);

    expect($calendar->access)->toBe(CalendarAccess::Restricted)
        ->and($calendar->audiences()->count())->toBe(1)
        ->and($calendar->audiences()->whereMorphedTo('audience', $user)->exists())->toBeTrue()
        ->and(fetchCalendarEvents($calendar)->pluck('id')->all())->toContain($event->id);
});

it('requires an audience for restricted custom calendars but not public calendars', function (): void {
    Filament::setCurrentPanel('admin');

    livewire(ListCalendars::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Empty Restricted Calendar',
            'access' => CalendarAccess::Restricted->value,
        ])
        ->assertHasActionErrors(['audiences_list']);

    livewire(ListCalendars::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Public Community Calendar',
            'access' => CalendarAccess::Public->value,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Calendar::query()->where('name', 'Empty Restricted Calendar')->exists())->toBeFalse()
        ->and(Calendar::query()->where('name', 'Public Community Calendar')->value('access'))->toBe(CalendarAccess::Public);
});

it('routes course events to calendars through course calendar tags', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Competition Team']);
    $course->syncTagsWithType([Calendar::SLUG_COMP], Course::CALENDAR_TAG_TYPE);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => calendarBySlug(Calendar::SLUG_EAC)->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    assignStudentToCurrentCompetition($user);

    $this->actingAs($user);

    expect(fetchCalendarEvents(calendarBySlug(Calendar::SLUG_EAC))->pluck('id')->all())->not->toContain($event->id)
        ->and(fetchCalendarEvents(calendarBySlug(Calendar::SLUG_COMP))->pluck('id')->all())->toContain($event->id)
        ->and(fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY))->pluck('id')->all())->toContain($event->id);
});

it('defaults new courses to the eac calendar tag', function (): void {
    $course = Course::factory()->create();

    expect($course->tagsWithType(Course::CALENDAR_TAG_TYPE)->pluck('name')->all())->toContain(Calendar::SLUG_EAC);
});

it('shows comp calendar when an owned student is on a current competition team', function (): void {
    $user = User::factory()->create();
    assignStudentToCurrentCompetition($user);
    $calendar = calendarBySlug(Calendar::SLUG_COMP);
    $event = standaloneEvent('Comp Rehearsal', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('shows comp calendar when staff is on a current competition team', function (): void {
    $user = User::factory()->isTeacher()->create();
    $calendar = calendarBySlug(Calendar::SLUG_COMP);
    currentCompetitionTeam()->staff()->attach($user);
    $event = standaloneEvent('Comp Staff Rehearsal', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('uses current competition accounts in the comp calendar exclusion picker', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create();
    $user->givePermissionTo('Create:Event');
    $parent = User::factory()->create();
    $student = Student::factory()->for($parent)->create();
    $staff = User::factory()->isTeacher()->create();
    $unrelatedTeacher = User::factory()->isTeacher()->create();
    $team = currentCompetitionTeam();
    $student->competitionTeams()->attach($team);
    $staff->competitionTeams()->attach($team);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->mountAction(CalendarCreateAction::class)
        ->fillForm(['calendar_id' => calendarBySlug(Calendar::SLUG_COMP)->id])
        ->assertSchemaComponentExists(
            'excluded_user_ids',
            checkComponentUsing: function (Select $select) use ($parent, $staff, $unrelatedTeacher): bool {
                $options = $select->getOptions();

                return isset($options[$parent->id], $options[$staff->id])
                    && ! isset($options[$unrelatedTeacher->id]);
            },
        );
});

it('does not show fixed internal calendars through a direct student audience', function (string $slug): void {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $calendar = calendarBySlug($slug);
    $calendar->audiences()->create([
        'audience_type' => $student->getMorphClass(),
        'audience_id' => $student->id,
    ]);
    $event = standaloneEvent('Internal Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->not->toContain($event->id);
})->with([
    Calendar::SLUG_OWNERS,
    Calendar::SLUG_STAFF,
]);

it('shows restricted custom calendars when an owned student is granted access', function (): void {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Team Calendar']);
    $student = Student::factory()->create(['user_id' => $user->id]);
    $calendar->audiences()->create([
        'audience_type' => $student->getMorphClass(),
        'audience_id' => $student->id,
    ]);
    $event = standaloneEvent('Team Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('shows restricted custom calendars when the user is granted access', function (): void {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Staff Team Calendar']);
    $calendar->audiences()->create([
        'audience_type' => $user->getMorphClass(),
        'audience_id' => $user->id,
    ]);
    $event = standaloneEvent('Staff Team Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('keeps course roster calendar access live for enrolled families and teachers', function (): void {
    $parent = User::factory()->create();
    $student = Student::factory()->for($parent)->create();
    $teacher = User::factory()->isTeacher()->create();
    $otherUser = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Live Roster']);
    $course->teachers()->sync([$teacher->id]);
    $calendar = Calendar::factory()->create(['name' => 'Live Roster Calendar']);
    $calendar->audiences()->create([
        'audience_type' => $course->getMorphClass(),
        'audience_id' => $course->id,
    ]);
    $event = standaloneEvent('Roster Event', $calendar);

    $this->actingAs($parent);
    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->not->toContain($event->id);

    Enrollment::factory()->withStudent($student)->create([
        'user_id' => $parent->id,
        'course_id' => $course->id,
    ]);

    expect($calendar->usersWithAccess()->pluck('users.id')->all())
        ->toContain($parent->id, $teacher->id)
        ->not->toContain($otherUser->id);

    $this->actingAs($parent);
    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->toContain($event->id);

    $this->actingAs($teacher);
    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->toContain($event->id);

    $this->actingAs($otherUser);
    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->not->toContain($event->id);
});

it('hides restricted custom calendars without a matching audience', function (string $panel): void {
    Filament::setCurrentPanel($panel);

    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Restricted Calendar']);
    $otherUser = User::factory()->create();
    $calendar->audiences()->create([
        'audience_type' => $otherUser->getMorphClass(),
        'audience_id' => $otherUser->id,
    ]);
    $event = standaloneEvent('Restricted Rehearsal', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->not->toContain($event->id);
})->with(['admin', 'user']);

it('keeps public system calendars visible regardless of audience records', function (): void {
    $user = User::factory()->create();
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $otherUser = User::factory()->create();
    $calendar->audiences()->create([
        'audience_type' => $otherUser->getMorphClass(),
        'audience_id' => $otherUser->id,
    ]);
    $event = standaloneEvent('Public Community Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('removes audience records when a system calendar is saved', function (string $slug): void {
    $calendar = calendarBySlug($slug);
    CalendarAudience::factory()->for($calendar)->create();

    $calendar->touch();

    expect($calendar->audiences()->exists())->toBeFalse();
})->with([
    Calendar::SLUG_MY,
    Calendar::SLUG_EAC,
]);

it('uses fixed roles for restricted system calendar visibility', function (string $slug, string $allowedRole): void {
    $calendar = calendarBySlug($slug);
    $allowedUser = User::factory()->create();
    $otherUser = User::factory()->create();
    $event = standaloneEvent('Restricted System Event', $calendar);

    $allowedUser->assignRole($allowedRole);

    $this->actingAs($allowedUser);

    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->toContain($event->id);

    $this->actingAs($otherUser);

    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->not->toContain($event->id);
})->with([
    [Calendar::SLUG_OWNERS, 'owner'],
    [Calendar::SLUG_STAFF, 'teacher'],
]);

it('prevents required system calendars from being deleted', function (): void {
    $calendar = calendarBySlug(Calendar::SLUG_COMP);

    expect($calendar->delete())->toBeFalse()
        ->and(Calendar::query()->whereKey($calendar->id)->exists())->toBeTrue();
});

it('does not restrict the admin calendar resource table by custom calendar audiences', function (): void {
    Filament::setCurrentPanel('admin');

    $calendar = Calendar::factory()->create(['name' => 'Resource Managed Calendar']);

    livewire(ListCalendars::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$calendar]);
});

it('does not show the internal slug on the admin calendar table', function (): void {
    Filament::setCurrentPanel('admin');

    livewire(ListCalendars::class)
        ->assertTableColumnDoesNotExist('slug');
});

it('does not allow availability or audiences to be changed on system calendars', function (string $slug): void {
    Filament::setCurrentPanel('admin');

    $calendar = calendarBySlug($slug);
    $user = User::factory()->create();

    livewire(ListCalendars::class)
        ->callAction(TestAction::make(EditAction::class)->table($calendar), data: [
            'name' => $calendar->name,
            'background_color' => '#123456',
            'access' => CalendarAccess::Restricted->value,
            'audiences_list' => [[
                'audience_type' => $user->getMorphClass(),
                'audience_id' => $user->id,
                'label' => $user->fullName,
            ]],
        ])
        ->assertNotified();

    expect($calendar->refresh()->access)->toBeNull()
        ->and($calendar->audiences()->exists())->toBeFalse();
})->with([
    Calendar::SLUG_MY,
    Calendar::SLUG_EAC,
    Calendar::SLUG_OWNERS,
    Calendar::SLUG_STAFF,
    Calendar::SLUG_COMP,
]);

it('hides excluded events from otherwise eligible users', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $otherTeacher = User::factory()->isTeacher()->create();
    $calendar = calendarBySlug(Calendar::SLUG_STAFF);
    $event = standaloneEvent('Staff Planning', $calendar);
    $event->excludedUsers()->sync([$teacher->id]);

    $this->actingAs($teacher);

    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->not->toContain($event->id);

    $this->actingAs($otherTeacher);

    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->toContain($event->id);
});

it('lets exclusions override direct my calendar invitations', function (): void {
    $user = User::factory()->create();
    $event = standaloneEvent('Private Meeting');
    EventAttendee::factory()->forUser($user)->create(['event_id' => $event->id]);
    $event->excludedUsers()->sync([$user->id]);

    $this->actingAs($user);

    expect(fetchCalendarEvents(calendarBySlug(Calendar::SLUG_MY))->pluck('id')->all())->not->toContain($event->id);
});

it('does not add resource urls to calendar feed events', function (): void {
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = standaloneEvent('Admin Visible Event', $calendar);

    Filament::setCurrentPanel('admin');

    $adminEvent = fetchCalendarEvents($calendar)->firstWhere('id', $event->id);

    Filament::setCurrentPanel('user');

    $userEvent = fetchCalendarEvents($calendar)->firstWhere('id', $event->id);

    expect($adminEvent)
        ->not->toHaveKey('url')
        ->and($adminEvent)->not->toHaveKey('shouldOpenUrlInNewTab')
        ->and($userEvent)->not->toHaveKey('url');
});

it('opens admin calendar events in the modal with permitted admin actions', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->isOwner()->create();
    $user->givePermissionTo(['View:Event', 'Update:Event', 'Cancel:Event']);
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = standaloneEvent('Admin Modal Event', $calendar);
    $fullEventUrl = EventResource::getUrl(name: 'view', parameters: ['record' => $event]);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertActionVisible(EditAction::class)
        ->assertActionVisible('cancelEvent')
        ->assertActionVisible('viewFullEvent')
        ->assertActionHasUrl('viewFullEvent', $fullEventUrl)
        ->assertActionDoesNotExist('addCourseProductToCart')
        ->assertActionDoesNotExist('viewCourseProductInStore');
});

it('hides admin calendar edit and full event actions without permission', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Calendar');
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = standaloneEvent('Read Only Admin Modal Event', $calendar);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertActionHidden(EditAction::class)
        ->assertActionHidden('cancelEvent')
        ->assertActionHidden('viewFullEvent');
});

it('hides cancellation for a completed event in the admin calendar modal', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->isOwner()->create();
    $user->givePermissionTo(['View:Event', 'Cancel:Event']);
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = Event::factory()->create([
        'name' => 'Completed Admin Event',
        'course_id' => null,
        'calendar_id' => $calendar->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertActionHidden('cancelEvent');
});

it('cancels an event from the admin calendar modal', function (): void {
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $user = User::factory()->isOwner()->create();
    $user->givePermissionTo(['View:Event', 'Cancel:Event']);
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = standaloneEvent('Cancelled From Calendar', $calendar);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->callAction(
            TestAction::make('cancelEvent')->arguments(['send_email' => false]),
            ['reason' => 'Cancelled from the calendar.'],
        )
        ->assertNotified('Event cancelled without sending email');

    $calendarEvent = fetchCalendarEvents($calendar)->firstWhere('id', $event->id);

    expect($event->refresh()->isCancelled())->toBeTrue()
        ->and($calendarEvent['title'])->toBe('Cancelled: Cancelled From Calendar')
        ->and($calendarEvent['backgroundColor'])->toBe('#6b7280')
        ->and($calendarEvent['editable'])->toBeFalse()
        ->and($calendarEvent['extendedProps']['isCancelled'])->toBeTrue();
    Mail::assertNothingQueued();
});

it('mounts the admin calendar create action with attendee fields', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create();
    $user->givePermissionTo('Create:Event');

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->mountAction(CalendarCreateAction::class)
        ->assertOk();
});

it('hides the admin calendar create action for teachers without event create permission', function (): void {
    Filament::setCurrentPanel('admin');

    $teacher = User::factory()->isTeacher()->create();
    $teacher->givePermissionTo('Manage:DashboardAppearance');

    $this->actingAs($teacher);

    livewire(CalendarWidget::class)
        ->assertActionHidden(CalendarCreateAction::class);
});

it('loads direct invitations with attendee names in a repeater', function (): void {
    $event = standaloneEvent('Private Rehearsal');
    $user = User::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    $student = Student::factory()->create([
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
    ]);

    EventAttendee::factory()->forUser($user)->create(['event_id' => $event->id]);
    EventAttendee::factory()->forStudent($student)->create(['event_id' => $event->id]);

    $attendees = eventFormAttendeeState($event);
    $attendeeRepeater = eventFormComponent('attendees_list');

    expect(collect($attendees)->pluck('label')->all())
        ->toContain('Ada Lovelace', 'Grace Hopper')
        ->and($attendeeRepeater)->toBeInstanceOf(Repeater::class);
});

it('saves direct invitations through the shared people picker', function (): void {
    $event = standaloneEvent('Private Rehearsal');
    $user = User::factory()->create();
    $student = Student::factory()->create();

    PeopleAndGroupsPicker::saveEventInvitations($event, [[
        'attendee_type' => $user->getMorphClass(),
        'attendee_id' => $user->id,
    ]]);

    expect($event->attendees()->whereMorphedTo('attendee', $user)->exists())->toBeTrue();

    PeopleAndGroupsPicker::saveEventInvitations($event, [[
        'attendee_type' => $student->getMorphClass(),
        'attendee_id' => $student->id,
    ]]);

    expect($event->attendees()->whereMorphedTo('attendee', $user)->exists())->toBeFalse()
        ->and($event->attendees()->whereMorphedTo('attendee', $student)->exists())->toBeTrue();
});

it('scopes people and group picker options to active teaching assignments', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $activeCourse = Course::factory()->create(['name' => 'Active Course']);
    $activeCourse->teachers()->sync([$teacher->id]);
    Event::factory()->create([
        'course_id' => $activeCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $activeUser = User::factory()->create();
    $activeStudent = Student::factory()->for($activeUser)->create();
    Enrollment::factory()->withStudent($activeStudent)->create([
        'course_id' => $activeCourse->id,
        'user_id' => $activeUser->id,
    ]);

    $concludedCourse = Course::factory()->create(['name' => 'Concluded Course']);
    $concludedCourse->teachers()->sync([$teacher->id]);
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => now()->subDay()->subHour(),
        'end_time' => now()->subDay(),
    ]);
    $concludedUser = User::factory()->create();
    $concludedStudent = Student::factory()->for($concludedUser)->create();
    Enrollment::factory()->withStudent($concludedStudent)->create([
        'course_id' => $concludedCourse->id,
        'user_id' => $concludedUser->id,
    ]);

    $otherCourse = Course::factory()->create(['name' => 'Other Course']);
    Event::factory()->create([
        'course_id' => $otherCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $otherUser = User::factory()->create();
    $otherStudent = Student::factory()->for($otherUser)->create();
    Enrollment::factory()->withStudent($otherStudent)->create([
        'course_id' => $otherCourse->id,
        'user_id' => $otherUser->id,
    ]);
    $owner = User::factory()->isOwner()->create();

    $this->actingAs($teacher);

    $courseOptions = eventFormComponent('add_course')->getOptions();
    $studentOptions = eventFormComponent('add_student')->getOptions();
    $userOptions = eventFormComponent('add_user')->getOptions();

    expect($courseOptions)
        ->toHaveKey($activeCourse->id)
        ->not->toHaveKeys([$concludedCourse->id, $otherCourse->id])
        ->and($studentOptions)
        ->toHaveKey($activeStudent->id)
        ->not->toHaveKeys([$concludedStudent->id, $otherStudent->id])
        ->and($userOptions)
        ->toHaveKeys([$activeUser->id, $owner->id])
        ->not->toHaveKeys([$concludedUser->id, $otherUser->id]);

    $this->actingAs($owner);

    expect(eventFormComponent('add_course')->getOptions())
        ->toHaveKeys([$activeCourse->id, $concludedCourse->id, $otherCourse->id])
        ->and(eventFormComponent('add_student')->getOptions())
        ->toHaveKeys([$activeStudent->id, $concludedStudent->id, $otherStudent->id])
        ->and(eventFormComponent('add_user')->getOptions())
        ->toHaveKeys([$activeUser->id, $concludedUser->id, $otherUser->id]);
});

it('rejects inaccessible picker submissions while preserving existing inaccessible invitations', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $unrelatedUser = User::factory()->create();
    $event = standaloneEvent('Scoped Invitations');
    EventAttendee::factory()->forUser($unrelatedUser)->create(['event_id' => $event->id]);

    $this->actingAs($teacher);

    expect(fn () => PeopleAndGroupsPicker::saveEventInvitations($event, [[
        'attendee_type' => $unrelatedUser->getMorphClass(),
        'attendee_id' => $unrelatedUser->id,
    ]]))->toThrow(ValidationException::class);

    PeopleAndGroupsPicker::saveEventInvitations($event, [[
        'attendee_type' => $student->getMorphClass(),
        'attendee_id' => $student->id,
    ]]);

    expect($event->attendees()->whereMorphedTo('attendee', $unrelatedUser)->exists())->toBeTrue()
        ->and($event->attendees()->whereMorphedTo('attendee', $student)->exists())->toBeTrue()
        ->and(collect(PeopleAndGroupsPicker::eventInvitationState($event))->pluck('attendee_id')->all())
        ->toBe([$student->id]);
});

it('opens user calendar event details as a modal instead of a slideover', function (): void {
    Filament::setCurrentPanel('user');

    $action = calendarWidgetViewAction(new CalendarWidget());

    expect($action->isModalSlideOver())->toBeFalse();
});

it('runs the calendar selection action and closes the dropdown after selecting a calendar action', function (): void {
    $widget = new CalendarWidget();
    $widget->mount();

    $headerActions = calendarWidgetHeaderActions($widget);
    $calendarActionGroup = collect($headerActions)->first(fn ($action): bool => $action instanceof ActionGroup);
    $calendarAction = collect($calendarActionGroup->getFlatActions())->first();

    expect($calendarAction->getAlpineClickHandler())
        ->toContain('mountAction(')
        ->toContain('close()');
});

it('updates the selected calendar when a calendar is selected', function (): void {
    $user = User::factory()->isTeacher()->create();
    $calendar = calendarBySlug(Calendar::SLUG_STAFF);

    $this->actingAs($user);

    $widget = new CalendarWidget();
    $widget->mount();
    $widget->selectCalendar($calendar->id);

    expect($widget->selectedCalendarId)->toBe($calendar->id);
});

it('defaults the selected calendar to the eac calendar', function (): void {
    $this->actingAs(User::factory()->create());

    $widget = new CalendarWidget();
    $widget->mount();

    expect($widget->selectedCalendarId)->toBe(calendarBySlug(Calendar::SLUG_EAC)->id);
});

it('adds an event course product to the cart from the user event modal', function (): void {
    $user = User::factory()->create();
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $course = Course::factory()->create(['capacity' => 5]);
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->callAction('addCourseProductToCart')
        ->assertNotified('Added to cart');

    expect(CartItem::query()
        ->where('user_id', $user->id)
        ->where('product_id', $product->id)
        ->value('quantity'))->toBe(1);
});

it('hides the calendar quick add but keeps view in store for products with add-time questions', function (): void {
    $user = User::factory()->create();
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $course = Course::factory()->create(['capacity' => 5]);
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    ProductQuestion::factory()->for($product)->required()->create();
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionHidden('addCourseProductToCart')
        ->assertActionVisible('viewCourseProductInStore');
});

it('hides event course product actions when the product is scheduled for later', function (): void {
    $user = User::factory()->create();
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $course = Course::factory()->create(['capacity' => 5]);
    Product::factory()
        ->forCourse($course)
        ->availableFrom(now()->addDay())
        ->create(['price' => 5000]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionHidden('addCourseProductToCart')
        ->assertActionHidden('viewCourseProductInStore');
});

it('renders regular calendar events with a pointer cursor and holidays with a default cursor', function (): void {
    expect((new CalendarWidget())->eventDidMount())
        ->toContain("'pointer'")
        ->toContain("'default'");
});

it('stacks the calendar toolbar controls on mobile screens', function (): void {
    $theme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($theme)
        ->toContain('@media (max-width: 639px)')
        ->toContain('.filament-fullcalendar .fc-header-toolbar')
        ->toContain('flex-direction: column')
        ->toContain('min-height: 2.75rem');
});

function fetchCalendarEvents(
    ?Calendar $calendar = null,
    string $start = '2027-01-01T00:00:00',
    string $end = '2027-01-31T23:59:59',
): Illuminate\Support\Collection {
    $widget = new CalendarWidget();
    $widget->selectedCalendarId = $calendar?->id;
    $widget->mount();

    return collect($widget->fetchEvents([
        'start' => $start,
        'end' => $end,
    ]));
}

function calendarWidgetHeaderActions(CalendarWidget $widget): array
{
    $method = new ReflectionMethod(CalendarWidget::class, 'headerActions');
    $method->setAccessible(true);

    return $method->invoke($widget);
}

function calendarWidgetViewAction(CalendarWidget $widget): Action
{
    $method = new ReflectionMethod(CalendarWidget::class, 'viewAction');
    $method->setAccessible(true);

    return $method->invoke($widget);
}

function eventFormAttendeeState(Event $event): array
{
    return PeopleAndGroupsPicker::eventInvitationState($event);
}

function eventFormComponent(string $name): mixed
{
    return findEventFormComponent(EventForm::components(), $name);
}

function findEventFormComponent(array $components, string $name): mixed
{
    foreach ($components as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }

        $children = rawEventFormChildComponents($component);

        if ($children !== []) {
            $childComponent = findEventFormComponent($children, $name);

            if ($childComponent !== null) {
                return $childComponent;
            }
        }
    }

    return null;
}

function rawEventFormChildComponents(mixed $component): array
{
    if (! is_object($component)) {
        return [];
    }

    $reflection = new ReflectionObject($component);

    while (! $reflection->hasProperty('childComponents')) {
        $parent = $reflection->getParentClass();

        if ($parent === false) {
            return [];
        }

        $reflection = $parent;
    }

    $property = $reflection->getProperty('childComponents');
    $property->setAccessible(true);
    $children = $property->getValue($component);

    return is_array($children['default'] ?? null) ? $children['default'] : [];
}

function standaloneEvent(string $name, ?Calendar $calendar = null): Event
{
    return Event::factory()->create([
        'name' => $name,
        'course_id' => null,
        'calendar_id' => $calendar?->id ?? calendarBySlug(Calendar::SLUG_EAC)->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);
}

function calendarBySlug(string $slug): Calendar
{
    return Calendar::query()->where('slug', $slug)->firstOrFail();
}

function assignableEventCalendarNames(User $user): array
{
    return Calendar::query()
        ->where('slug', '!=', Calendar::SLUG_MY)
        ->assignableBy($user)
        ->orderBy('id')
        ->pluck('name')
        ->all();
}

function currentCompetitionTeam(): CompetitionTeam
{
    return CompetitionTeam::query()
        ->current()
        ->first() ?? CompetitionTeam::factory()
        ->for(CompetitionSeason::factory()->current(), 'season')
        ->create();
}

function assignStudentToCurrentCompetition(User $user): Student
{
    $student = Student::factory()->for($user)->create();
    $student->competitionTeams()->attach(currentCompetitionTeam());

    return $student;
}
