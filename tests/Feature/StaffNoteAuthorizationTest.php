<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Students\Pages\ViewStudent;
use App\Filament\Admin\Resources\Students\RelationManagers\StaffNotesRelationManager;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\StaffNote;
use App\Models\Student;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('groups staff note row actions at the start of the table', function (): void {
    $owner = User::factory()->isOwner()->create();
    $student = Student::factory()->create();

    $this->actingAs($owner);

    $component = livewire(StaffNotesRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass' => ViewStudent::class,
    ])->loadTable();

    $table = $component->instance()->getTable();

    expect($table->getRecordActionsPosition())
        ->toBe(RecordActionsPosition::BeforeCells)
        ->and($table->getRecordActions())
        ->toHaveCount(1)
        ->and($table->getRecordActions()[0])
        ->toBeInstanceOf(ActionGroup::class)
        ->and($table->getFlatRecordActions())
        ->toHaveKeys(['view', 'edit', 'delete']);
});

it('allows permitted staff to view all notes for an accessible student but manage only their own', function (): void {
    $author = User::factory()->isTeacher()->create();
    $colleague = User::factory()->isTeacher()->create();
    $outsider = User::factory()->isTeacher()->create();
    $owner = User::factory()->isOwner()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$author->id, $colleague->id]);
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
    $note = StaffNote::factory()->create([
        'student_id' => $student->id,
        'author_id' => $author->id,
    ]);

    $this->actingAs($author);
    expect(Gate::allows('view', $note))->toBeTrue()
        ->and(Gate::allows('update', $note))->toBeTrue()
        ->and(Gate::allows('delete', $note))->toBeTrue();

    $this->actingAs($colleague);
    expect(Gate::allows('view', $note))->toBeTrue()
        ->and(Gate::allows('update', $note))->toBeFalse()
        ->and(Gate::allows('delete', $note))->toBeFalse();

    $this->actingAs($owner);
    expect(Gate::allows('view', $note))->toBeTrue()
        ->and(Gate::allows('update', $note))->toBeTrue()
        ->and(Gate::allows('delete', $note))->toBeTrue();

    $this->actingAs($outsider);
    expect(Gate::allows('view', $note))->toBeFalse()
        ->and(Gate::allows('update', $note))->toBeFalse()
        ->and(Gate::allows('delete', $note))->toBeFalse();
});

it('creates a staff note for the viewed student with the authenticated author', function (): void {
    Storage::fake(MediaDisks::private());

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

    $this->actingAs($teacher);

    livewire(StaffNotesRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass' => ViewStudent::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), data: [
            'note' => 'Student needs additional warmup time.',
            'documents' => [
                UploadedFile::fake()->create('care-plan.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->image('diagram.png'),
            ],
        ])
        ->assertHasNoActionErrors();

    assertDatabaseHas(StaffNote::class, [
        'student_id' => $student->id,
        'author_id' => $teacher->id,
        'note' => 'Student needs additional warmup time.',
    ]);

    $note = StaffNote::query()
        ->where('student_id', $student->id)
        ->where('author_id', $teacher->id)
        ->firstOrFail();

    $documents = $note->getMedia('documents');

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('disk')->unique()->values()->all())
        ->toBe([MediaDisks::private()])
        ->and($documents->pluck('file_name')->all())
        ->toBe(['care-plan.pdf', 'diagram.png']);

    livewire(StaffNotesRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass' => ViewStudent::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('view')->table($note))
        ->assertActionMounted(TestAction::make('view')->table($note))
        ->assertSchemaComponentExists('documents', 'mountedActionSchema0');
});
