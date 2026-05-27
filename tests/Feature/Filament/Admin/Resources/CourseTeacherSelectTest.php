<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Courses\Schemas\CourseForm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\Select;
use Spatie\Permission\Models\Role;

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

    $results = Select::make('teacher_id')
        ->model(Course::class)
        ->searchableRelationship(
            name: 'teacher',
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
