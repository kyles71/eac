<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\StaffNotes\Schemas\StaffNoteForm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\StaffNote;
use App\Models\Student;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

it('configures staff note documents as multiple private uploads', function (): void {
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '';
        }
    };

    $schema = StaffNoteForm::configure(Schema::make($livewire));
    $documents = $schema->getComponent('documents');

    expect($documents)->toBeInstanceOf(SpatieMediaLibraryFileUpload::class)
        ->and($documents->getDiskName())->toBe(MediaDisks::private())
        ->and($documents->getVisibility())->toBe('private')
        ->and($documents->isMultiple())->toBeTrue();
});

it('authorizes private staff note document downloads through the associated student', function (): void {
    Storage::fake(MediaDisks::private());

    $teacher = User::factory()->isTeacher()->create();
    $outsider = User::factory()->isTeacher()->create();
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
    $note = StaffNote::factory()->create([
        'student_id' => $student->id,
        'author_id' => $teacher->id,
    ]);
    $media = $note
        ->addMedia(UploadedFile::fake()->create('care-plan.pdf', 20, 'application/pdf'))
        ->toMediaCollection('documents', MediaDisks::private());
    $url = route('admin.staff-notes.documents.download', [
        'staffNote' => $note,
        'media' => $media,
    ]);

    $this->actingAs($teacher)
        ->get($url)
        ->assertOk()
        ->assertDownload('care-plan.pdf');

    $this->actingAs($outsider)
        ->get($url)
        ->assertForbidden();
});

it('does not download media belonging to a different staff note', function (): void {
    Storage::fake(MediaDisks::private());

    $owner = User::factory()->isOwner()->create();
    $firstNote = StaffNote::factory()->create();
    $otherNote = StaffNote::factory()->create();
    $otherMedia = $otherNote
        ->addMedia(UploadedFile::fake()->create('other.pdf', 20, 'application/pdf'))
        ->toMediaCollection('documents', MediaDisks::private());

    $this->actingAs($owner)
        ->get(route('admin.staff-notes.documents.download', [
            'staffNote' => $firstNote,
            'media' => $otherMedia,
        ]))
        ->assertNotFound();
});
