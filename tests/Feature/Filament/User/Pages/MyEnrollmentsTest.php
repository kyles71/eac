<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Filament\User\Pages\MyEnrollments;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Enums\RecordActionsPosition;
use Illuminate\Contracts\Support\Htmlable;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('can render the my classes page', function () {
    livewire(MyEnrollments::class)
        ->assertOk();
});

it('groups row actions at the start of the table', function () {
    $component = livewire(MyEnrollments::class)
        ->loadTable();

    $recordActions = $component->instance()->getTable()->getRecordActions();

    expect($component->instance()->getTable()->getRecordActionsPosition())
        ->toBe(RecordActionsPosition::BeforeCells)
        ->and($recordActions)
        ->toHaveCount(1)
        ->and($recordActions[0])
        ->toBeInstanceOf(ActionGroup::class)
        ->and($component->instance()->getTable()->getFlatRecordActions())
        ->toHaveKeys(['viewCourseDetails', 'assignStudent', 'removeStudent']);
});

it('scopes the sticky action column styling to the my classes page', function () {
    $themeCss = file_get_contents(resource_path('css/filament/user/theme.css'));
    $cssRuleContainingSelector = function (string $selector) use ($themeCss): string {
        $matched = preg_match('/[^{}]*'.preg_quote($selector, '/').'[^{}]*\{[^}]*\}/', $themeCss, $matches);

        expect($matched)->toBe(1);

        return $matches[0];
    };

    $bodyActionCellSelector = '.fi-user-my-enrollments-page .fi-ta-table>tbody>tr>.fi-ta-cell:has(> .fi-ta-actions):first-child';
    $headerActionCellSelector = '.fi-user-my-enrollments-page .fi-ta-table>thead>tr>.fi-ta-actions-header-cell:first-child';

    expect((new MyEnrollments)->getPageClasses())
        ->toContain('fi-user-my-enrollments-page')
        ->and($themeCss)
        ->toContain('.fi-user-my-enrollments-page')
        ->toContain('position: sticky')
        ->toContain('inset-inline-start: 0')
        ->toContain(':focus-within')
        ->toContain('body:has(.fi-user-my-enrollments-page) .fi-dropdown-panel')
        ->toContain('z-index: 50');

    expect($themeCss)
        ->toContain($bodyActionCellSelector)
        ->toContain($headerActionCellSelector);

    expect($cssRuleContainingSelector($headerActionCellSelector))
        ->toContain('z-index: 20')
        ->not->toContain('z-index: 30');

    expect($cssRuleContainingSelector($bodyActionCellSelector))
        ->toContain('z-index: 10')
        ->not->toContain('z-index: 30');

    expect($cssRuleContainingSelector($bodyActionCellSelector.':focus-within'))
        ->toContain('z-index: 40')
        ->not->toContain('z-index: 30');

    expect($cssRuleContainingSelector('body:has(.fi-user-my-enrollments-page) .fi-dropdown-panel'))
        ->toContain('z-index: 50')
        ->not->toContain('z-index: 30');
});

it('can assign an open purchased enrollment to a student', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
    ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'all')
        ->callAction(TestAction::make('assignStudent')->table($enrollment), data: [
            'student_id' => $student->id,
        ])
        ->assertNotified('Enrollment updated');

    expect($enrollment->refresh()->student_id)->toBe($student->id);
});

it('can remove a student from an enrollment beyond the configured cutoff', function () {
    config(['app.enrollment_unassign_cutoff_days' => 7]);

    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->forSemester(CourseSemester::Fall)->create();
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDays(8),
        'end_time' => now()->addDays(8)->addHour(),
    ]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', CourseSemester::Fall->value)
        ->callAction(TestAction::make('removeStudent')->table($enrollment))
        ->assertNotified('Student removed from enrollment');

    expect($enrollment->refresh()->student_id)->toBeNull();
});

it('does not allow removing a student inside the configured cutoff', function () {
    config(['app.enrollment_unassign_cutoff_days' => 7]);

    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->forSemester(CourseSemester::Fall)->create();
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDays(6),
        'end_time' => now()->addDays(6)->addHour(),
    ]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', CourseSemester::Fall->value)
        ->assertActionHidden(TestAction::make('removeStudent')->table($enrollment));
});

it('groups current classes by semester and moves concluded classes to past', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    $winterCourse = Course::factory()->forSemester(CourseSemester::WinterSpring)->create();
    Event::factory()->create([
        'course_id' => $winterCourse->id,
        'start_time' => now()->subWeek(),
        'end_time' => now()->subWeek()->addHour(),
    ]);
    Event::factory()->create([
        'course_id' => $winterCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $winterEnrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $winterCourse->id,
            'user_id' => auth()->id(),
        ]);

    $summerCourse = Course::factory()->forSemester(CourseSemester::Summer)->create();
    Event::factory()->create([
        'course_id' => $summerCourse->id,
        'start_time' => now()->addMonth(),
        'end_time' => now()->addMonth()->addHour(),
    ]);
    $summerEnrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $summerCourse->id,
            'user_id' => auth()->id(),
        ]);

    $concludedCourse = Course::factory()->forSemester(CourseSemester::WinterSpring)->create();
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => now()->subWeek(),
        'end_time' => now()->subWeek()->addHour(),
    ]);
    $concludedEnrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $concludedCourse->id,
            'user_id' => auth()->id(),
        ]);

    $winterTabRecords = livewire(MyEnrollments::class)
        ->set('activeTab', CourseSemester::WinterSpring->value)
        ->instance()
        ->getTableRecords()
        ->getCollection()
        ->pluck('id');

    expect($winterTabRecords)->toContain($winterEnrollment->id)
        ->not->toContain($summerEnrollment->id)
        ->not->toContain($concludedEnrollment->id);

    $pastTabRecords = livewire(MyEnrollments::class)
        ->set('activeTab', 'past')
        ->instance()
        ->getTableRecords()
        ->getCollection()
        ->pluck('id');

    expect($pastTabRecords)->toContain($concludedEnrollment->id)
        ->not->toContain($winterEnrollment->id)
        ->not->toContain($summerEnrollment->id);
});

it('does not show assignment actions after a class has concluded', function () {
    $course = Course::factory()->forSemester(CourseSemester::Fall)->create();
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subWeek(),
        'end_time' => now()->subWeek()->addHour(),
    ]);
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'student_id' => null,
    ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'past')
        ->assertActionHidden(TestAction::make('assignStudent')->table($enrollment));
});

it('opens course details from a course row without calendar widget actions', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Katherine',
        'last_name' => 'Dunham',
        'staff_bio' => 'Katherine teaches dance history.',
    ]);
    $course = Course::factory()->forSemester(CourseSemester::Fall)->create([
        'name' => 'Tap Details',
        'description' => 'Bring tap shoes.',
    ]);
    $course->teachers()->sync([$teacher->id]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addMinutes(75),
    ]);
    $enrollment = Enrollment::factory()
        ->withStudent($student)
        ->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
        ]);

    livewire(MyEnrollments::class)
        ->set('activeTab', 'all')
        ->mountAction(TestAction::make('viewCourseDetails')->table($enrollment))
        ->assertActionMounted(TestAction::make('viewCourseDetails')->table($enrollment))
        ->assertSchemaComponentExists('teacher', 'mountedActionSchema0', function (TextEntry $entry): bool {
            $state = $entry->formatState($entry->getState());

            return $state instanceof Htmlable
                && str_contains($state->toHtml(), 'Katherine Dunham')
                && str_contains($state->toHtml(), 'Katherine teaches dance history.');
        })
        ->assertActionDataSet(fn (array $data): bool => $data['name'] === $course->name
            && $data['semester'] === $course->academicTerm->display_name
            && $data['student'] === $student->fullName
            && $data['duration'] === '75 minutes'
            && $data['meetings'] === 1
            && $data['status'] === 'Future'
            && $data['teacher'] === 'Katherine Dunham'
            && ! array_key_exists('tags', $data)
            && $data['description'] === 'Bring tap shoes.')
        ->assertActionDoesNotExist('addCourseProductToCart')
        ->assertActionDoesNotExist('viewCourseProductInStore');
});

it('does not include students from other accounts in assign options', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $otherStudent = Student::factory()->create();
    $component = livewire(MyEnrollments::class);
    $method = new ReflectionMethod(MyEnrollments::class, 'studentOptions');
    $method->setAccessible(true);

    expect($method->invoke($component->instance()))
        ->toHaveKey($student->id)
        ->not->toHaveKey($otherStudent->id);
});
