<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Students\Pages\ListStudents;
use App\Models\Enrollment;
use App\Models\EventAttendee;
use App\Models\RecurringPrivateLesson;
use App\Models\Student;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

it('stores general student tags', function (): void {
    livewire(ListStudents::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Avery',
            'last_name' => 'Stone',
            'birthdate' => '2015-04-12',
            'tags' => ['has sibling'],
        ])
        ->assertNotified();

    $student = Student::query()->where('first_name', 'Avery')->firstOrFail();

    expect($student->tagsWithType(Student::GENERAL_TAG_TYPE)->pluck('name')->all())->toBe(['has sibling']);
});

it('shows student directory columns with parent context', function (): void {
    livewire(ListStudents::class)
        ->assertTableColumnExists('full_name')
        ->assertTableColumnExists('nickname')
        ->assertTableColumnExists('birthdate')
        ->assertTableColumnExists('age')
        ->assertTableColumnExists('user.full_name')
        ->assertTableColumnExists('user.email');
});

it('bulk deletes only students without course or event history', function (): void {
    $deletableStudent = Student::factory()->create();
    $enrolledStudent = Student::factory()->create();
    $eventAttendee = Student::factory()->create();

    Enrollment::factory()->withStudent($enrolledStudent)->create();
    EventAttendee::factory()->forStudent($eventAttendee)->create();

    livewire(ListStudents::class)
        ->loadTable()
        ->selectTableRecords([$deletableStudent, $enrolledStudent, $eventAttendee])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    assertDatabaseMissing($deletableStudent);
    assertDatabaseHas(Student::class, ['id' => $enrolledStudent->id]);
    assertDatabaseHas(Student::class, ['id' => $eventAttendee->id]);
});

it('prevents deleting students with course or event history through the model', function (string $history): void {
    $student = Student::factory()->create();

    match ($history) {
        'course' => Enrollment::factory()->withStudent($student)->create(),
        'event' => EventAttendee::factory()->forStudent($student)->create(),
    };

    expect(fn (): ?bool => $student->delete())
        ->toThrow(ValidationException::class, 'Students enrolled in courses or attending events cannot be deleted.');

    assertDatabaseHas(Student::class, ['id' => $student->id]);
})->with(['course', 'event']);

it('restricts deleting course-related students at the database level', function (string $history): void {
    $student = Student::factory()->create();

    match ($history) {
        'enrollment' => Enrollment::factory()->withStudent($student)->create(),
        'recurring private lesson' => RecurringPrivateLesson::factory()->create([
            'student_id' => $student->id,
            'user_id' => $student->user_id,
        ]),
    };

    expect(fn (): int => DB::table('students')->where('id', $student->id)->delete())
        ->toThrow(QueryException::class);

    assertDatabaseHas(Student::class, ['id' => $student->id]);
})->with(['enrollment', 'recurring private lesson']);
