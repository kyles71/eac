<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Students\Pages\ListStudents;
use App\Filament\Admin\Resources\Students\StudentResource;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('grants owners and super admins email access through the Sprint 3 migration', function (): void {
    $owner = Role::findByName(Role::OWNER);
    $superAdmin = Role::findByName(Role::SUPER_ADMIN);

    $owner->revokePermissionTo('Send:Email');
    $superAdmin->revokePermissionTo('Send:Email');

    $migration = require database_path('migrations/2026_07_30_204312_grant_send_email_permission_to_owners.php');
    $migration->up();

    expect($owner->hasPermissionTo('Send:Email'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('Send:Email'))->toBeTrue();
});

it('grants teachers email access through the Sprint 6 migration', function (): void {
    $teacher = Role::findByName(Role::TEACHER);

    $teacher->revokePermissionTo('Send:Email');

    $migration = require database_path('migrations/2026_07_31_004235_grant_send_email_permission_to_teachers.php');
    $migration->up();

    expect($teacher->hasPermissionTo('Send:Email'))->toBeTrue();
});

it('limits teachers to assigned course events even after a course is completed', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $assignedCourse = Course::factory()->create();
    $assignedCourse->teachers()->sync([$teacher->id]);
    $otherCourse = Course::factory()->create();
    $assignedEvent = Event::factory()->create([
        'name' => 'Scoped Event Search',
        'course_id' => $assignedCourse->id,
        'start_time' => now()->subMonths(2),
        'end_time' => now()->subMonths(2)->addHour(),
    ]);
    $otherEvent = Event::factory()->create([
        'name' => 'Scoped Event Search',
        'course_id' => $otherCourse->id,
    ]);
    $standaloneEvent = Event::factory()->create(['course_id' => null]);
    $assignedEvent->update(['name' => 'Scoped Event Search']);
    $otherEvent->update(['name' => 'Scoped Event Search']);

    $this->actingAs($teacher);

    expect(Gate::allows('view', $assignedEvent))->toBeTrue()
        ->and(Gate::allows('update', $assignedEvent))->toBeTrue()
        ->and(Gate::allows('view', $otherEvent))->toBeFalse()
        ->and(Gate::allows('update', $otherEvent))->toBeFalse()
        ->and(Gate::allows('view', $standaloneEvent))->toBeFalse();

    livewire(ListEvents::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$assignedEvent])
        ->assertCanNotSeeTableRecords([$otherEvent, $standaloneEvent]);

    expect(EventResource::getGlobalSearchEloquentQuery()->where('name', 'Scoped Event Search')->count())->toBe(1)
        ->and(EventResource::canView($assignedEvent))->toBeTrue()
        ->and(EventResource::getGlobalSearchResults('Scoped Event Search'))->toHaveCount(1);

    $this->get(EventResource::getUrl('view', ['record' => $otherEvent]))
        ->assertNotFound();
});

it('allows owners to view and update every event', function (): void {
    $owner = User::factory()->isOwner()->create();
    $courseEvent = Event::factory()->create();
    $standaloneEvent = Event::factory()->create(['course_id' => null]);

    $this->actingAs($owner);

    expect(Gate::allows('view', $courseEvent))->toBeTrue()
        ->and(Gate::allows('update', $courseEvent))->toBeTrue()
        ->and(Gate::allows('view', $standaloneEvent))->toBeTrue()
        ->and(Gate::allows('update', $standaloneEvent))->toBeTrue();

    livewire(ListEvents::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$courseEvent, $standaloneEvent]);
});

it('prevents teachers from assigning an event to a course they do not teach', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $assignedCourse = Course::factory()->create();
    $assignedCourse->teachers()->sync([$teacher->id]);
    $otherCourse = Course::factory()->create();
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_EAC)->firstOrFail();
    $event = Event::factory()->create([
        'course_id' => $assignedCourse->id,
        'calendar_id' => $calendar->id,
    ]);

    $this->actingAs($teacher);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->callAction(EditAction::class, data: [
            'name' => $event->name,
            'course_id' => $otherCourse->id,
            'focus' => $event->focus,
            'description' => $event->description,
            'details' => $event->details,
            'start_time' => $event->start_time,
            'end_time' => $event->end_time,
            'calendar_id' => $calendar->id,
        ])
        ->assertHasActionErrors(['course_id']);

    expect($event->refresh()->course_id)->toBe($assignedCourse->id);
});

it('limits teachers to students from actively taught courses', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $activeCourse = Course::factory()->create();
    $activeCourse->teachers()->sync([$teacher->id]);
    Event::factory()->create([
        'course_id' => $activeCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $activeStudent = Student::factory()->create([
        'first_name' => 'Scoped',
        'last_name' => 'Student',
    ]);
    Enrollment::factory()->withStudent($activeStudent)->create([
        'course_id' => $activeCourse->id,
        'user_id' => $activeStudent->user_id,
    ]);

    $concludedCourse = Course::factory()->create();
    $concludedCourse->teachers()->sync([$teacher->id]);
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => now()->subYear(),
        'end_time' => now()->subYear()->addHour(),
    ]);
    $concludedStudent = Student::factory()->create([
        'first_name' => 'Scoped',
        'last_name' => 'Student',
    ]);
    Enrollment::factory()->withStudent($concludedStudent)->create([
        'course_id' => $concludedCourse->id,
        'user_id' => $concludedStudent->user_id,
    ]);

    $otherCourse = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $otherCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $otherStudent = Student::factory()->create([
        'first_name' => 'Scoped',
        'last_name' => 'Student',
    ]);
    Enrollment::factory()->withStudent($otherStudent)->create([
        'course_id' => $otherCourse->id,
        'user_id' => $otherStudent->user_id,
    ]);

    $this->actingAs($teacher);

    expect(Gate::allows('view', $activeStudent))->toBeTrue()
        ->and(Gate::allows('view', $concludedStudent))->toBeFalse()
        ->and(Gate::allows('view', $otherStudent))->toBeFalse();

    livewire(ListStudents::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$activeStudent])
        ->assertCanNotSeeTableRecords([$concludedStudent, $otherStudent])
        ->assertActionVisible(TestAction::make('sendEmail')->table($activeStudent));

    expect(StudentResource::getGlobalSearchResults('Scoped Student'))->toHaveCount(1);

    $this->get(StudentResource::getUrl('view', ['record' => $concludedStudent]))
        ->assertNotFound();
    $this->get(StudentResource::getUrl('view', ['record' => $otherStudent]))
        ->assertNotFound();
});

it('allows owners to view every student', function (): void {
    $owner = User::factory()->isOwner()->create();
    $students = Student::factory()->count(2)->create();

    $this->actingAs($owner);

    foreach ($students as $student) {
        expect(Gate::allows('view', $student))->toBeTrue();
    }

    livewire(ListStudents::class)
        ->loadTable()
        ->assertCanSeeTableRecords($students);
});
