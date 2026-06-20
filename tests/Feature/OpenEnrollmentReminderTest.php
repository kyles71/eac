<?php

declare(strict_types=1);

use App\Actions\Mail\SendOpenEnrollmentReminders;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

it('registers the customizable open enrollment reminder with user and enrollment data', function (): void {
    $definition = app(EmailTypeRegistry::class)->get('open-enrollment-reminder');

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->tokensByKey()))
        ->toContain('user.first_name', 'user.email', 'open_enrollments.count')
        ->and(array_keys($definition->slotsByMergeTag()))->toBe(['slot.open-enrollments']);
});

it('batches all eligible open enrollments into one reminder per user and sends each once', function (): void {
    Mail::fake();
    $user = User::factory()->create([
        'first_name' => 'Jamie',
        'email' => 'open-enrollments@example.com',
    ]);
    $ballet = Course::factory()->create(['name' => 'Ballet <One>']);
    $jazz = Course::factory()->create(['name' => 'Jazz Two']);
    $newCourse = Course::factory()->create(['name' => 'New Course']);
    $first = Enrollment::factory()->create([
        'user_id' => $user->id,
        'course_id' => $ballet->id,
        'created_at' => now()->subDays(8),
    ]);
    $second = Enrollment::factory()->create([
        'user_id' => $user->id,
        'course_id' => $jazz->id,
        'created_at' => now()->subDays(10),
    ]);
    $newEnrollment = Enrollment::factory()->create([
        'user_id' => $user->id,
        'course_id' => $newCourse->id,
        'created_at' => now()->subDays(6),
    ]);
    Enrollment::factory()->withStudent(Student::factory()->create(['user_id' => $user->id]))->create([
        'user_id' => $user->id,
        'created_at' => now()->subDays(10),
    ]);

    expect(app(SendOpenEnrollmentReminders::class)->handle())->toBe([
        'users_reminded' => 1,
        'enrollments_marked' => 2,
    ])->and(app(SendOpenEnrollmentReminders::class)->handle())->toBe([
        'users_reminded' => 0,
        'enrollments_marked' => 0,
    ]);

    expect($first->refresh()->assignment_reminder_sent_at)->not->toBeNull()
        ->and($second->refresh()->assignment_reminder_sent_at)->not->toBeNull()
        ->and($newEnrollment->refresh()->assignment_reminder_sent_at)->toBeNull();

    Mail::assertQueued(ManagedMail::class, 1);
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'open-enrollment-reminder'
            && $mail->hasTo('open-enrollments@example.com')
            && $mail->usesMailer('transactional')
            && $rendered->subject === 'Complete your 2 open enrollment(s)'
            && str_contains($rendered->html, 'Ballet &lt;One&gt;')
            && str_contains($rendered->html, 'Jazz Two')
            && ! str_contains($rendered->html, 'New Course');
    });
});

it('does not mark open enrollments when the reminder type is disabled', function (): void {
    Mail::fake();
    $enrollment = Enrollment::factory()->create(['created_at' => now()->subDays(8)]);
    app(ManagedTemplateRepository::class)->saveOverride('open-enrollment-reminder', [
        'is_active' => false,
    ]);

    expect(app(SendOpenEnrollmentReminders::class)->handle())->toBe([
        'users_reminded' => 0,
        'enrollments_marked' => 0,
    ])->and($enrollment->refresh()->assignment_reminder_sent_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('runs open enrollment reminders through the command', function (): void {
    Mail::fake();
    Enrollment::factory()->create(['created_at' => now()->subDays(8)]);

    $this->artisan('enrollments:send-open-reminders')
        ->expectsOutput('Reminded 1 user(s) about 1 open enrollment(s).')
        ->assertSuccessful();

    Mail::assertQueued(ManagedMail::class, 1);
});
