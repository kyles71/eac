<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\StudentCommunications\StudentCommunicationResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('reuses the email permission for student communications', function (): void {
    $userWithoutEmailPermission = User::factory()->create();
    $userWithEmailPermission = User::factory()->create();
    $communication = StudentCommunication::factory()->create();
    $userWithoutEmailPermission->givePermissionTo('View:Student');
    $userWithEmailPermission->givePermissionTo([
        'Send:Email',
        'View:Student',
    ]);
    $course = Course::factory()->create();
    $course->teachers()->sync([
        $userWithoutEmailPermission->id,
        $userWithEmailPermission->id,
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Enrollment::factory()->withStudent($communication->student)->create([
        'course_id' => $course->id,
        'user_id' => $communication->student->user_id,
    ]);

    $this->actingAs($userWithoutEmailPermission);

    expect(Gate::allows('viewAny', StudentCommunication::class))->toBeFalse()
        ->and(Gate::allows('create', StudentCommunication::class))->toBeFalse()
        ->and(Gate::allows('view', $communication))->toBeFalse();

    $this->actingAs($userWithEmailPermission);

    expect(Gate::allows('viewAny', StudentCommunication::class))->toBeTrue()
        ->and(Gate::allows('create', StudentCommunication::class))->toBeTrue()
        ->and(Gate::allows('view', $communication))->toBeTrue();
});

it('allows teachers to view communications for active students they teach', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $taughtCourse = Course::factory()->create();
    $taughtCourse->teachers()->attach($teacher);
    Event::factory()->create([
        'course_id' => $taughtCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $taughtStudent = Student::factory()->create();
    Enrollment::factory()->withStudent($taughtStudent)->create([
        'course_id' => $taughtCourse->id,
        'user_id' => $taughtStudent->user_id,
    ]);
    $unrelatedStudent = Student::factory()->create();
    $visibleCommunication = StudentCommunication::factory()->for($taughtStudent)->create();
    $hiddenCommunication = StudentCommunication::factory()->for($unrelatedStudent)->create();

    $this->actingAs($teacher);

    expect(Gate::allows('viewAny', StudentCommunication::class))->toBeTrue()
        ->and(Gate::allows('create', StudentCommunication::class))->toBeTrue()
        ->and(Gate::allows('view', $visibleCommunication))->toBeTrue()
        ->and(Gate::allows('view', $hiddenCommunication))->toBeFalse()
        ->and(Gate::allows('update', $visibleCommunication))->toBeFalse()
        ->and(Gate::allows('delete', $visibleCommunication))->toBeFalse();
});

it('hides communications after the teaching course concludes', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $concludedCourse = Course::factory()->create();
    $concludedCourse->teachers()->attach($teacher);
    Event::factory()->create([
        'course_id' => $concludedCourse->id,
        'start_time' => now()->subYear(),
        'end_time' => now()->subYear()->addHour(),
    ]);
    $formerStudent = Student::factory()->create();
    Enrollment::factory()->withStudent($formerStudent)->create([
        'course_id' => $concludedCourse->id,
        'user_id' => $formerStudent->user_id,
    ]);
    $communication = StudentCommunication::factory()->for($formerStudent)->create();

    $this->actingAs($teacher);

    expect(Gate::allows('view', $formerStudent))->toBeFalse()
        ->and(Gate::allows('view', $communication))->toBeFalse();
});

it('keeps the student communication resource immutable and off navigation', function (): void {
    expect(StudentCommunicationResource::shouldRegisterNavigation())->toBeFalse()
        ->and(StudentCommunicationResource::getPages())->toBe([])
        ->and(config('filament-shield.resources.manage.'.StudentCommunicationResource::class))
        ->toBeNull()
        ->and(config('filament-shield.resources.exclude'))
        ->toContain(StudentCommunicationResource::class);
});
