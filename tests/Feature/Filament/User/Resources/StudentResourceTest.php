<?php

declare(strict_types=1);

use App\Filament\User\Resources\Students\Pages\ListStudents;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('can render the students index page', function () {
    livewire(ListStudents::class)
        ->assertOk();
});

it('only lists students on the authenticated account', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $otherStudent = Student::factory()->create(['user_id' => User::factory()]);

    livewire(ListStudents::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$student])
        ->assertCanNotSeeTableRecords([$otherStudent]);
});

it('can create a student for the authenticated account', function () {
    livewire(ListStudents::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Avery',
            'last_name' => 'Dancer',
        ])
        ->assertNotified();

    assertDatabaseHas(Student::class, [
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
        'user_id' => auth()->id(),
    ]);
});

it('can delete a student without enrollments', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    livewire(ListStudents::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($student))
        ->assertNotified();

    assertDatabaseMissing(Student::class, [
        'id' => $student->id,
    ]);
});

it('hides delete for students with enrollments', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    Enrollment::factory()
        ->withStudent($student)
        ->create([
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(ListStudents::class)
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table($student));
});
