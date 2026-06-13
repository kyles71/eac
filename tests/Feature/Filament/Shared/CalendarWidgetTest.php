<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Calendars\Pages\ListCalendars;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Models\Calendar;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Product;
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
use Saade\FilamentFullCalendar\Actions\CreateAction as CalendarCreateAction;
use Spatie\Permission\Models\Role;
use Spatie\Tags\Tag;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    foreach (Calendar::systemCalendarDefinitions() as $slug => $calendar) {
        Calendar::query()->updateOrCreate(['slug' => $slug], $calendar);
    }

    seedSystemCalendarAudienceTags();
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

it('shows public tagged custom calendars to every authenticated user', function (): void {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Community Calendar']);
    $calendar->attachTag(Calendar::AUDIENCE_TAG_PUBLIC, Calendar::AUDIENCE_TAG_TYPE);
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

it('shows owner calendars through default user audience tags', function (string $slug): void {
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
    $customCalendarUser->attachTag('Company', Calendar::AUDIENCE_TAG_TYPE);

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

it('allows users with calendar policy access to assign events to all shared calendars', function (): void {
    $scheduler = User::factory()->create();
    $scheduler->givePermissionTo('ViewAny:Calendar');

    $calendarNames = assignableEventCalendarNames($scheduler);

    expect($calendarNames)->toContain('EAC Calendar', 'Owners', 'Staff', 'Comp Calendar')
        ->not->toContain('My Calendar');
});

it('supports future roles through user audience tags', function (): void {
    $advisorRole = Role::findOrCreate('advisor');
    $advisor = User::factory()->create();
    $advisor->assignRole($advisorRole);
    $advisor->attachTag(Calendar::AUDIENCE_TAG_STAFF, Calendar::AUDIENCE_TAG_TYPE);
    $calendar = calendarBySlug(Calendar::SLUG_STAFF);
    $event = standaloneEvent('Advisor Staff Event', $calendar);

    $this->actingAs($advisor);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('supports custom calendar audience tags and user grants', function (): void {
    Filament::setCurrentPanel('admin');

    livewire(ListCalendars::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Company Calendar',
            'background_color' => '#123456',
            'audience_tag_ids' => [Tag::findOrCreate('Company', Calendar::AUDIENCE_TAG_TYPE)->id],
        ])
        ->assertNotified();

    $calendar = Calendar::query()->where('name', 'Company Calendar')->firstOrFail();
    $user = User::factory()->create();
    $user->attachTag('Company', Calendar::AUDIENCE_TAG_TYPE);
    $event = standaloneEvent('Company Planning', $calendar);

    $this->actingAs($user);

    expect($calendar->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toBe(['Company'])
        ->and(fetchCalendarEvents($calendar)->pluck('id')->all())->toContain($event->id);
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

it('can reapply the calendar access migration after rollback without duplicating system calendars or tags', function (): void {
    $migration = include database_path('migrations/2026_05_31_002547_add_access_fields_to_calendars_table.php');

    $migration->down();
    $migration->up();

    expect(Calendar::query()->where('name', 'Owners')->count())->toBe(1)
        ->and(Calendar::query()->where('name', 'Staff')->count())->toBe(1)
        ->and(Calendar::query()->where('name', 'Comp Calendar')->count())->toBe(1)
        ->and(calendarBySlug(Calendar::SLUG_MY)->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toContain(Calendar::AUDIENCE_TAG_PUBLIC)
        ->and(calendarBySlug(Calendar::SLUG_EAC)->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toContain(Calendar::AUDIENCE_TAG_PUBLIC)
        ->and(calendarBySlug(Calendar::SLUG_OWNERS)->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toContain(Calendar::AUDIENCE_TAG_OWNERS)
        ->and(calendarBySlug(Calendar::SLUG_STAFF)->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toContain(Calendar::AUDIENCE_TAG_STAFF)
        ->and(calendarBySlug(Calendar::SLUG_COMP)->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())->toBe([]);
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

    $parent = User::factory()->create();
    $student = Student::factory()->for($parent)->create();
    $staff = User::factory()->isTeacher()->create();
    $unrelatedTeacher = User::factory()->isTeacher()->create();
    $team = currentCompetitionTeam();
    $student->competitionTeams()->attach($team);
    $staff->competitionTeams()->attach($team);

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

it('does not show internal calendars when only an owned student has the matching audience tag', function (string $slug, string $tag): void {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $calendar = calendarBySlug($slug);
    $student->attachTag($tag, Calendar::AUDIENCE_TAG_TYPE);
    $event = standaloneEvent('Internal Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->not->toContain($event->id);
})->with([
    [Calendar::SLUG_OWNERS, Calendar::AUDIENCE_TAG_OWNERS],
    [Calendar::SLUG_STAFF, Calendar::AUDIENCE_TAG_STAFF],
]);

it('shows audience tagged custom calendars when an owned student has a matching tag', function (): void {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Team Calendar']);
    $calendar->attachTag('team', Calendar::AUDIENCE_TAG_TYPE);
    $student = Student::factory()->create(['user_id' => $user->id]);
    $student->attachTag('team', Calendar::AUDIENCE_TAG_TYPE);
    $event = standaloneEvent('Team Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('shows audience tagged custom calendars when the user has a matching tag', function (): void {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Staff Team Calendar']);
    $calendar->attachTag('staff-team', Calendar::AUDIENCE_TAG_TYPE);
    $user->attachTag('staff-team', Calendar::AUDIENCE_TAG_TYPE);
    $event = standaloneEvent('Staff Team Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->toContain($event->id);
});

it('hides audience tagged calendars from the widget feed without a matching audience tag', function (string $panel): void {
    Filament::setCurrentPanel($panel);

    $user = User::factory()->create();
    $calendar = Calendar::factory()->create(['name' => 'Restricted Calendar']);
    $calendar->attachTag('company', Calendar::AUDIENCE_TAG_TYPE);
    $event = standaloneEvent('Restricted Rehearsal', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($events->pluck('id')->all())->not->toContain($event->id);
})->with(['admin', 'user']);

it('keeps my calendar and eac calendar visible in the widget even when audience tagged', function (): void {
    $user = User::factory()->create();
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $tag = Tag::findOrCreate('restricted', Calendar::AUDIENCE_TAG_TYPE);
    $calendar->tags()->attach($tag);
    $event = standaloneEvent('Public Community Event', $calendar);

    $this->actingAs($user);

    $events = fetchCalendarEvents($calendar);

    expect($calendar->refresh()->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())
        ->toContain(Calendar::AUDIENCE_TAG_PUBLIC, 'restricted')
        ->and($events->pluck('id')->all())->toContain($event->id);
});

it('keeps the public audience tag on public system calendars when audience tags are synced', function (string $slug): void {
    $calendar = calendarBySlug($slug);

    $calendar->syncTagsWithType(['restricted'], Calendar::AUDIENCE_TAG_TYPE);

    expect($calendar->refresh()->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())
        ->toContain(Calendar::AUDIENCE_TAG_PUBLIC, 'restricted');
})->with([
    Calendar::SLUG_MY,
    Calendar::SLUG_EAC,
]);

it('uses seeded audience tags for restricted system calendar visibility', function (string $slug, string $tagName): void {
    $calendar = calendarBySlug($slug);
    $allowedUser = User::factory()->create();
    $otherUser = User::factory()->create();
    $event = standaloneEvent('Restricted System Event', $calendar);

    $allowedUser->attachTag($tagName, Calendar::AUDIENCE_TAG_TYPE);

    $this->actingAs($allowedUser);

    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->toContain($event->id);

    $this->actingAs($otherUser);

    expect(fetchCalendarEvents($calendar)->pluck('id')->all())->not->toContain($event->id);
})->with([
    [Calendar::SLUG_OWNERS, Calendar::AUDIENCE_TAG_OWNERS],
    [Calendar::SLUG_STAFF, Calendar::AUDIENCE_TAG_STAFF],
]);

it('prevents required system calendars from being deleted', function (): void {
    $calendar = calendarBySlug(Calendar::SLUG_COMP);

    expect($calendar->delete())->toBeFalse()
        ->and(Calendar::query()->whereKey($calendar->id)->exists())->toBeTrue();
});

it('does not restrict the admin calendar resource table by widget audience tags', function (): void {
    Filament::setCurrentPanel('admin');

    $calendar = Calendar::factory()->create(['name' => 'Resource Managed Calendar']);
    $calendar->attachTag('restricted', Calendar::AUDIENCE_TAG_TYPE);

    livewire(ListCalendars::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$calendar]);
});

it('does not show the internal slug on the admin calendar table', function (): void {
    Filament::setCurrentPanel('admin');

    livewire(ListCalendars::class)
        ->assertTableColumnDoesNotExist('slug');
});

it('does not allow audience tags to be changed on system calendars', function (string $slug, array $expectedAudienceTags): void {
    Filament::setCurrentPanel('admin');

    $calendar = calendarBySlug($slug);
    $tag = Tag::findOrCreate('Updated Audience', Calendar::AUDIENCE_TAG_TYPE);

    livewire(ListCalendars::class)
        ->callAction(TestAction::make(EditAction::class)->table($calendar), data: [
            'name' => $calendar->name,
            'background_color' => '#123456',
            'audience_tag_ids' => [$tag->id],
        ])
        ->assertNotified();

    expect($calendar->refresh()->tagsWithType(Calendar::AUDIENCE_TAG_TYPE)->pluck('name')->all())
        ->toBe($expectedAudienceTags);
})->with([
    [Calendar::SLUG_MY, [Calendar::AUDIENCE_TAG_PUBLIC]],
    [Calendar::SLUG_EAC, [Calendar::AUDIENCE_TAG_PUBLIC]],
    [Calendar::SLUG_OWNERS, [Calendar::AUDIENCE_TAG_OWNERS]],
    [Calendar::SLUG_STAFF, [Calendar::AUDIENCE_TAG_STAFF]],
    [Calendar::SLUG_COMP, []],
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

    $user = User::factory()->create();
    $user->givePermissionTo(['View:Event', 'Update:Event']);
    $calendar = calendarBySlug(Calendar::SLUG_EAC);
    $event = standaloneEvent('Admin Modal Event', $calendar);
    $fullEventUrl = EventResource::getUrl(name: 'view', parameters: ['record' => $event]);

    $this->actingAs($user);

    livewire(CalendarWidget::class)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertActionVisible(EditAction::class)
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
        ->assertActionHidden('viewFullEvent');
});

it('mounts the admin calendar create action with attendee fields', function (): void {
    Filament::setCurrentPanel('admin');

    $this->actingAs(User::factory()->create());

    livewire(CalendarWidget::class)
        ->mountAction(CalendarCreateAction::class)
        ->assertOk();
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

it('renders regular calendar events with a pointer cursor and holidays with a default cursor', function (): void {
    expect((new CalendarWidget())->eventDidMount())
        ->toContain("'pointer'")
        ->toContain("'default'");
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
    $method = new ReflectionMethod(EventForm::class, 'attendeeState');
    $method->setAccessible(true);

    return $method->invoke(null, $event);
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

function seedSystemCalendarAudienceTags(): void
{
    calendarBySlug(Calendar::SLUG_MY)->attachTag(Calendar::AUDIENCE_TAG_PUBLIC, Calendar::AUDIENCE_TAG_TYPE);
    calendarBySlug(Calendar::SLUG_EAC)->attachTag(Calendar::AUDIENCE_TAG_PUBLIC, Calendar::AUDIENCE_TAG_TYPE);
    calendarBySlug(Calendar::SLUG_OWNERS)->attachTag(Calendar::AUDIENCE_TAG_OWNERS, Calendar::AUDIENCE_TAG_TYPE);
    calendarBySlug(Calendar::SLUG_STAFF)->attachTag(Calendar::AUDIENCE_TAG_STAFF, Calendar::AUDIENCE_TAG_TYPE);
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
