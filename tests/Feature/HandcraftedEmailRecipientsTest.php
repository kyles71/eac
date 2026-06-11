<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use App\Support\HandcraftedEmailRecipients;

it('searches students and teachers by partial full name after three characters', function (): void {
    $student = Student::factory()->create([
        'first_name' => 'Avery',
        'last_name' => 'Stone',
    ]);
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Taylor',
        'last_name' => 'Swift',
    ]);
    $nonTeacher = User::factory()->create([
        'first_name' => 'Taylor',
        'last_name' => 'Smith',
    ]);

    $recipients = app(HandcraftedEmailRecipients::class);

    expect($recipients->search('av'))->toBe([])
        ->and($recipients->search('avery st')['Students'])->toHaveKey("student:{$student->id}", 'Avery Stone')
        ->and($recipients->search('taylor sw')['Teachers'])->toHaveKey("teacher:{$teacher->id}", 'Taylor Swift')
        ->and($recipients->search('taylor sm')['Teachers'] ?? [])->not->toHaveKey("teacher:{$nonTeacher->id}");
});

it('offers complete valid email addresses as direct recipients', function (): void {
    $recipients = app(HandcraftedEmailRecipients::class);

    expect($recipients->search('person@example.com')['Email address'])
        ->toBe(['person@example.com' => 'person@example.com'])
        ->and($recipients->search('not-an-email'))->not->toHaveKey('Email address');
});

it('labels people by name and direct recipients by email address', function (): void {
    $student = Student::factory()->create([
        'first_name' => 'Avery',
        'last_name' => 'Stone',
    ]);
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Taylor',
        'last_name' => 'Swift',
    ]);

    expect(app(HandcraftedEmailRecipients::class)->labels([
        "student:{$student->id}",
        "teacher:{$teacher->id}",
        'person@example.com',
    ]))->toBe([
        "student:{$student->id}" => 'Avery Stone',
        "teacher:{$teacher->id}" => 'Taylor Swift',
        'person@example.com' => 'person@example.com',
    ]);
});

it('resolves a student to the associated user and every additional email', function (): void {
    $user = User::factory()->create(['email' => 'parent@example.com']);
    $student = Student::factory()->for($user)->create();

    StudentEmail::factory()->for($student)->create(['email' => 'dancer@example.com']);
    StudentEmail::factory()->for($student)->create(['email' => 'PARENT@example.com']);

    expect(app(HandcraftedEmailRecipients::class)->resolve([
        "student:{$student->id}",
        'DANCER@example.com',
    ]))->toBe([
        'parent@example.com',
        'dancer@example.com',
    ]);
});

it('resolves teacher tokens but rejects non-teacher user tokens', function (): void {
    $teacher = User::factory()->isTeacher()->create(['email' => 'teacher@example.com']);
    $nonTeacher = User::factory()->create(['email' => 'user@example.com']);

    expect(app(HandcraftedEmailRecipients::class)->resolve([
        "teacher:{$teacher->id}",
        "teacher:{$nonTeacher->id}",
    ]))->toBe(['teacher@example.com']);
});
