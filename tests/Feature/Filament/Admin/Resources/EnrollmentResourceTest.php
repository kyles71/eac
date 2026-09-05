<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Enrollments\Pages\ListEnrollments;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Carbon::setTestNow('2026-06-27 11:47:04');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('synchronizes and scopes the household and student fields when creating an enrollment', function (): void {
    $this->actingAs(User::factory()->isSuperAdmin()->create());
    $household = User::factory()->create();
    $firstStudent = Student::factory()->for($household)->create();
    $secondStudent = Student::factory()->for($household)->create();
    $otherHousehold = User::factory()->create();
    $otherStudent = Student::factory()->for($otherHousehold)->create(['nickname' => 'Scout']);

    $createPage = livewire(ListEnrollments::class)
        ->assertActionVisible(TestAction::make('create'))
        ->mountAction(TestAction::make('create'));
    $schemaName = $createPage->instance()->getMountedActionSchemaName();
    $schema = $createPage->instance()->{$schemaName};
    $statePath = $schema->getStatePath();
    $studentSelect = $schema->getFlatComponents()['student_id'];

    expect($studentSelect)
        ->toBeInstanceOf(Select::class)
        ->and($studentSelect->isPreloaded())->toBeTrue()
        ->and($studentSelect->hasDynamicOptions())->toBeTrue()
        ->and($studentSelect->getOptions())
        ->toHaveKeys([$firstStudent->id, $secondStudent->id, $otherStudent->id])
        ->and($studentSelect->getSearchResults('Scout'))->toHaveKey($otherStudent->id);

    $createPage->set("{$statePath}.user_id", $household->id);
    $studentSelect = $createPage->instance()->{$schemaName}->getFlatComponents()['student_id'];

    expect($studentSelect->getOptions())
        ->toHaveKeys([$firstStudent->id, $secondStudent->id])
        ->not->toHaveKey($otherStudent->id)
        ->and($studentSelect->getSearchResults('Scout'))->not->toHaveKey($otherStudent->id);

    expect(Validator::make(
        ['student_id' => $otherStudent->id],
        ['student_id' => $studentSelect->getValidationRules()],
    )->fails())->toBeTrue();

    $createPage
        ->set("{$statePath}.student_id", $firstStudent->id)
        ->set("{$statePath}.user_id", $otherHousehold->id)
        ->assertActionDataSet(['student_id' => null]);

    $createPage
        ->set("{$statePath}.user_id", null)
        ->set("{$statePath}.student_id", $secondStudent->id)
        ->assertActionDataSet(['user_id' => $household->id]);
});

it('lists every assigned enrollment for the same active course', function (): void {
    $activeCourse = Course::factory()->create();

    Event::factory()->create([
        'course_id' => $activeCourse->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);
    Event::factory()->create([
        'course_id' => $activeCourse->id,
        'start_time' => Carbon::now()->addDay(),
        'end_time' => Carbon::now()->addDay()->addHour(),
    ]);

    $firstEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $activeCourse->id,
    ]);
    $secondEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $activeCourse->id,
    ]);
    $openEnrollment = Enrollment::factory()->create([
        'course_id' => $activeCourse->id,
        'student_id' => null,
    ]);

    $futureCourse = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $futureCourse->id,
        'start_time' => Carbon::now()->addWeek(),
        'end_time' => Carbon::now()->addWeek()->addHour(),
    ]);
    $futureEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $futureCourse->id,
    ]);

    $concludedCourse = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => Carbon::now()->subWeek(),
        'end_time' => Carbon::now()->subWeek()->addHour(),
    ]);
    $pastEnrollment = Enrollment::factory()->withStudent()->create([
        'course_id' => $concludedCourse->id,
    ]);

    livewire(ListEnrollments::class)
        ->set('activeTab', 'active')
        ->loadTable()
        ->assertCanSeeTableRecords([$firstEnrollment, $secondEnrollment])
        ->assertCanNotSeeTableRecords([$openEnrollment, $futureEnrollment, $pastEnrollment]);
});

it('shows assignment state and schedule context on the enrollment table', function (): void {
    $course = Course::factory()->create(['name' => 'Ballet 2']);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::now()->addWeek(),
        'end_time' => Carbon::now()->addWeek()->addHour(),
    ]);
    $openEnrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'student_id' => null,
    ]);

    livewire(ListEnrollments::class)
        ->set('activeTab', 'all')
        ->loadTable()
        ->assertCanSeeTableRecords([$openEnrollment])
        ->assertTableColumnExists('academic_term')
        ->assertTableColumnExists('next_class')
        ->assertTableColumnExists('assignment_status')
        ->assertTableColumnStateSet('assignment_status', 'Needs student', $openEnrollment)
        ->assertTableFilterExists('assignment_status')
        ->assertTableFilterExists('course_id');
});

it('converts a manual enrollment to a class hold from the table', function (): void {
    Mail::fake();
    config(['app.display_timezone' => 'America/New_York']);
    Carbon::setTestNow(Carbon::parse('2026-06-27 15:47:04', 'UTC'));

    $course = Course::factory()->create();
    Product::factory()->forCourse($course)->create(['price' => 14_000]);
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'order_item_id' => null,
    ]);

    livewire(ListEnrollments::class)
        ->set('activeTab', 'all')
        ->loadTable()
        ->callAction(TestAction::make('convertToHold')->table($enrollment), data: [
            'expires_at' => Carbon::now('America/New_York')->addMinutes(30)->format('Y-m-d H:i:s'),
            'notes' => 'Waiting for payment',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $hold = $enrollment->user->courseHolds()->where('notes', 'Waiting for payment')->sole();

    expect(Enrollment::query()->whereKey($enrollment->id)->exists())->toBeFalse()
        ->and($hold->expires_at->toDateTimeString())->toBe(Carbon::now()->addMinutes(30)->startOfMinute()->toDateTimeString());
});
