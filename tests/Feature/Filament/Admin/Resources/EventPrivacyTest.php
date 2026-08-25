<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

use function Pest\Livewire\livewire;

it('labels public and staff-only event fields and stores uploads privately', function (): void {
    Filament::setCurrentPanel('admin');
    $event = Event::factory()->create([
        'focus' => 'Turns',
        'description' => 'Public class description',
        'details' => 'Private choreography notes',
    ]);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertSee('Focus / Theme (Public)')
        ->assertSee('Public Description')
        ->assertSee('Lesson Plan (Staff Only)')
        ->assertSee('Private choreography notes')
        ->mountAction(EditAction::class)
        ->assertSchemaComponentExists(
            'images',
            'mountedActionSchema0',
            fn (SpatieMediaLibraryFileUpload $field): bool => $field->getDiskName() === MediaDisks::private()
                && $field->getVisibility() === 'private',
        );
});

it('does not hydrate private event content for teachers viewing unrelated admin calendar events', function (): void {
    Filament::setCurrentPanel('admin');
    $teacher = User::factory()->isTeacher()->create();
    $otherCourse = Course::factory()->create();
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->firstOrFail();
    $event = Event::factory()->create([
        'course_id' => $otherCourse->id,
        'calendar_id' => $calendar->id,
        'details' => 'Private lesson plan',
    ]);

    $this->actingAs($teacher);

    livewire(CalendarWidget::class)
        ->call('selectCalendar', $calendar->id)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertSchemaComponentHidden('details', 'mountedActionSchema0')
        ->assertActionDataSet(fn (array $data): bool => ! array_key_exists('details', $data))
        ->assertActionHidden(EditAction::class)
        ->assertActionHidden('viewFullEvent');
});

it('shows private event content to the teacher assigned to the course', function (): void {
    Filament::setCurrentPanel('admin');
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->firstOrFail();
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'details' => 'Private lesson plan',
    ]);

    $this->actingAs($teacher);

    livewire(CalendarWidget::class)
        ->call('selectCalendar', $calendar->id)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertSchemaComponentVisible('details', 'mountedActionSchema0')
        ->assertActionDataSet(fn (array $data): bool => ($data['details'] ?? null) === 'Private lesson plan')
        ->assertActionVisible(EditAction::class)
        ->assertActionVisible('viewFullEvent');
});

it('never hydrates lesson plans into the user calendar action', function (): void {
    Filament::setCurrentPanel('user');
    $family = User::factory()->create();
    $student = Student::factory()->for($family)->create();
    $course = Course::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $family->id,
    ]);
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_EAC)->firstOrFail();
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'details' => 'Private lesson plan',
    ]);

    $this->actingAs($family);

    livewire(CalendarWidget::class)
        ->call('selectCalendar', $calendar->id)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertSchemaComponentDoesNotExist('details', 'mountedActionSchema0')
        ->assertActionDataSet(fn (array $data): bool => ! array_key_exists('details', $data));
});
