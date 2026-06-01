<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Enums\ScheduleFrequency;
use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Courses\Schemas\CourseForm;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('registers the searchable relationship select macro', function () {
    expect(Select::hasMacro('searchableRelationship'))->toBeTrue()
        ->and(
            Select::make('user_id')->searchableRelationship(
                name: 'user',
                searchColumns: ['first_name', 'last_name'],
                labelFromRecord: fn (User $user): string => $user->fullName,
                orderBy: ['first_name', 'last_name'],
            )
        )
        ->toBeInstanceOf(Select::class);
});

it('searches teachers by partial full name terms', function () {
    $teacher = User::factory()->create([
        'first_name' => 'First',
        'last_name' => 'Last',
    ]);

    $otherTeacher = User::factory()->create([
        'first_name' => 'First',
        'last_name' => 'Other',
    ]);

    $results = Select::make('teachers')
        ->multiple()
        ->model(Course::class)
        ->searchableRelationship(
            name: 'teachers',
            searchColumns: ['first_name', 'last_name'],
            labelFromRecord: fn (User $user): string => $user->fullName,
            orderBy: ['first_name', 'last_name'],
        )
        ->getSearchResults('first l');

    expect($results)
        ->toHaveKey($teacher->id)
        ->not->toHaveKey($otherTeacher->id)
        ->and($results[$teacher->id])
        ->toBe('First Last');
});

it('saves multiple teachers from the course form', function () {
    $firstTeacher = User::factory()->create([
        'first_name' => 'Alvin',
        'last_name' => 'Ailey',
    ]);
    $secondTeacher = User::factory()->create([
        'first_name' => 'Twyla',
        'last_name' => 'Tharp',
    ]);

    $firstTeacher->assignRole('teacher');
    $secondTeacher->assignRole('teacher');

    livewire(ListCourses::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Ballet 1',
            'description' => null,
            'semester' => CourseSemester::WinterSpring->value,
            'capacity' => 10,
            'start_time' => now()->addWeek()->format('Y-m-d H:i:s'),
            'duration' => 60,
            'teachers' => [$firstTeacher->id, $secondTeacher->id],
            'guest_teacher' => null,
            'courseForms' => [],
        ])
        ->assertHasNoActionErrors();

    $course = Course::query()->where('name', 'Ballet 1')->firstOrFail();

    assertDatabaseHas('course_teacher', [
        'course_id' => $course->id,
        'teacher_id' => $firstTeacher->id,
    ]);
    assertDatabaseHas('course_teacher', [
        'course_id' => $course->id,
        'teacher_id' => $secondTeacher->id,
    ]);

    expect($course->tagsWithType(Course::CALENDAR_TAG_TYPE)->pluck('name')->all())->toContain(Calendar::SLUG_EAC);
});

it('saves selected course calendar tags from the course form', function () {
    $teacher = User::factory()->create();
    $teacher->assignRole('teacher');

    livewire(ListCourses::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Comp Team',
            'description' => null,
            'semester' => CourseSemester::Summer->value,
            'capacity' => 10,
            'start_time' => now()->addWeek()->format('Y-m-d H:i:s'),
            'duration' => 60,
            'calendar_tag_slugs' => [Calendar::SLUG_COMP],
            'teachers' => [$teacher->id],
            'guest_teacher' => null,
            'courseForms' => [],
        ])
        ->assertHasNoActionErrors();

    $course = Course::query()->where('name', 'Comp Team')->firstOrFail();

    expect($course->tagsWithType(Course::CALENDAR_TAG_TYPE)->pluck('name')->all())->toBe([Calendar::SLUG_COMP]);
});

it('stores general course tags separately from calendar course tags', function (): void {
    livewire(ListCourses::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Jazz Technique',
            'description' => null,
            'semester' => CourseSemester::Fall->value,
            'capacity' => 10,
            'start_time' => now()->addWeek()->format('Y-m-d H:i:s'),
            'duration' => 60,
            'calendar_tag_slugs' => [Calendar::SLUG_COMP],
            'teachers' => [],
            'guest_teacher' => null,
            'tags' => ['audition prep'],
            'courseForms' => [],
        ])
        ->assertHasNoActionErrors();

    $course = Course::query()->where('name', 'Jazz Technique')->firstOrFail();

    expect($course->tagsWithType(Course::GENERAL_TAG_TYPE)->pluck('name')->all())->toBe(['audition prep'])
        ->and($course->tagsWithType(Course::CALENDAR_TAG_TYPE)->pluck('name')->all())->toBe([Calendar::SLUG_COMP]);
});

it('creates recurring class events when creating a course', function (): void {
    livewire(ListCourses::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Modern 2',
            'description' => 'Technique and combinations',
            'semester' => CourseSemester::Fall->value,
            'capacity' => 12,
            'start_time' => '2027-01-01 10:00:00',
            'duration' => 90,
            'repeat_frequency' => ScheduleFrequency::Weekly->value,
            'repeat_through' => '2027-01-15',
            'calendar_tag_slugs' => [Calendar::SLUG_COMP],
            'teachers' => [],
            'guest_teacher' => null,
            'tags' => [],
            'courseForms' => [],
        ])
        ->assertHasNoActionErrors();

    $course = Course::query()->where('name', 'Modern 2')->firstOrFail();
    $calendar = Calendar::query()->where('slug', Calendar::SLUG_COMP)->firstOrFail();
    $events = $course->events()->orderBy('start_time')->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('name')->all())->toBe([
            'Modern 2 Class',
            'Modern 2 Class',
            'Modern 2 Class',
        ])
        ->and($events->pluck('start_time')->map->toDateTimeString()->all())->toBe([
            '2027-01-01 15:00:00',
            '2027-01-08 15:00:00',
            '2027-01-15 15:00:00',
        ])
        ->and($events->pluck('end_time')->map->toDateTimeString()->all())->toBe([
            '2027-01-01 16:30:00',
            '2027-01-08 16:30:00',
            '2027-01-15 16:30:00',
        ])
        ->and($events->pluck('calendar_id')->unique()->values()->all())->toBe([$calendar->id]);
});

it('includes the repeat through day when creating daily course events', function (): void {
    livewire(ListCourses::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Ballet Intensive',
            'description' => null,
            'semester' => CourseSemester::Summer->value,
            'capacity' => 12,
            'start_time' => '2026-05-11 20:00:00',
            'duration' => 60,
            'repeat_frequency' => ScheduleFrequency::Daily->value,
            'repeat_through' => '2026-05-15',
            'calendar_tag_slugs' => [Calendar::SLUG_EAC],
            'teachers' => [],
            'guest_teacher' => null,
            'tags' => [],
            'courseForms' => [],
        ])
        ->assertHasNoActionErrors();

    $course = Course::query()->where('name', 'Ballet Intensive')->firstOrFail();
    $eventDates = $course->events()
        ->orderBy('start_time')
        ->get()
        ->map(fn ($event): string => $event->start_time
            ->timezone(config('app.display_timezone'))
            ->toDateString())
        ->all();

    expect($eventDates)->toBe([
        '2026-05-11',
        '2026-05-12',
        '2026-05-13',
        '2026-05-14',
        '2026-05-15',
    ]);
});

it('limits course teacher options to users with the teacher role when it exists', function () {
    $teacher = User::factory()->create();
    $user = User::factory()->create();

    $teacher->assignRole('teacher');

    $results = CourseForm::scopeTeacherOptions(User::query())->pluck('id');

    expect($results)
        ->toContain($teacher->id)
        ->not->toContain($user->id);
});

it('returns all users for course teacher options when the teacher role does not exist', function () {
    Role::query()->where('name', 'teacher')->delete();

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $results = CourseForm::scopeTeacherOptions(User::query())->pluck('id');

    expect($results)
        ->toContain($firstUser->id)
        ->toContain($secondUser->id);
});

it('searches students by partial full name terms', function () {
    $student = Student::factory()->create([
        'first_name' => 'First',
        'last_name' => 'Last',
    ]);

    $otherStudent = Student::factory()->create([
        'first_name' => 'First',
        'last_name' => 'Other',
    ]);

    $results = Select::make('student_id')
        ->model(Enrollment::class)
        ->searchableRelationship(
            name: 'student',
            searchColumns: ['first_name', 'last_name'],
            labelFromRecord: fn (Student $student): string => $student->fullName,
            orderBy: ['first_name', 'last_name'],
        )
        ->getSearchResults('first l');

    expect($results)
        ->toHaveKey($student->id)
        ->not->toHaveKey($otherStudent->id)
        ->and($results[$student->id])
        ->toBe('First Last');
});

it('searches non user models by split terms', function () {
    $course = Course::factory()->create(['name' => 'Ballet 1']);
    $otherCourse = Course::factory()->create(['name' => 'Ballet 2']);

    $results = Select::make('requires_course_id')
        ->model(Product::class)
        ->searchableRelationship(
            name: 'requiresCourse',
            searchColumns: ['name'],
            labelFromRecord: fn (Course $course): string => $course->name,
            orderBy: ['name'],
        )
        ->getSearchResults('ball 1');

    expect($results)
        ->toHaveKey($course->id)
        ->not->toHaveKey($otherCourse->id)
        ->and($results[$course->id])
        ->toBe('Ballet 1');
});
