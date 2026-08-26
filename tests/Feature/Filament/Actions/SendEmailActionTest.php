<?php

declare(strict_types=1);

use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Students\Pages\ListStudents;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Mail\HandcraftedEmail;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('has the correct default name', function (): void {
    expect(SendEmailAction::getDefaultName())->toBe('sendEmail');
});

it('can set default to addresses from an array', function (): void {
    $emails = ['test@example.com', 'admin@example.com'];

    $action = SendEmailAction::make()
        ->to($emails);

    expect($action)->toBeInstanceOf(SendEmailAction::class);
});

it('can set default to addresses from a closure', function (): void {
    $emails = ['closure@example.com', 'dynamic@example.com'];

    $action = SendEmailAction::make()
        ->to(fn (): array => $emails);

    expect($action)->toBeInstanceOf(SendEmailAction::class);
});

it('queues a handcrafted email using the handcrafted mailer', function (): void {
    Mail::fake();

    $recipients = ['recipient@example.com'];
    $subject = 'Test Subject';
    $body = 'Test email body content';

    SendEmailAction::make()->call([
        'data' => [
            'to' => $recipients,
            'subject' => $subject,
            'body' => $body,
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, function (HandcraftedEmail $mail) use ($recipients, $subject, $body): bool {
        return $mail->hasTo($recipients[0])
            && $mail->usesMailer('handcrafted')
            && $mail->emailSubject === $subject
            && $mail->emailBody === $body;
    });
});

it('queues one handcrafted email per recipient by default', function (): void {
    Mail::fake();

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com', 'FIRST@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 2);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && ! $mail->hasTo('second@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('second@example.com')
        && ! $mail->hasTo('first@example.com'));
});

it('always queues SendEmailAction recipients individually', function (): void {
    Mail::fake();

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 2);
});

it('does not offer a per-email layout selection', function (): void {
    $user = User::factory()->create();

    livewire(ListUsers::class)
        ->loadTable()
        ->mountAction(TestAction::make('sendEmail')->table($user))
        ->assertSchemaComponentDoesNotExist('layout_mode', 'mountedActionSchema0')
        ->assertSchemaComponentDoesNotExist('layout_id', 'mountedActionSchema0');
});

it('uses the mail manager rich editor configuration for the email body', function (): void {
    $user = User::factory()->create();

    livewire(ListUsers::class)
        ->loadTable()
        ->mountAction(TestAction::make('sendEmail')->table($user))
        ->assertSchemaComponentExists('body', 'mountedActionSchema0', function ($component): bool {
            return $component instanceof RichEditor
                && $component->getToolbarButtons() === [
                    ['bold', 'italic', 'underline', 'strike', 'link'],
                    ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ]
                && str_contains(
                    (string) ($component->getExtraAttributes()['class'] ?? ''),
                    'fi-mail-manager-rich-editor',
                );
        });
});

it('queues one private email to every address associated with a student', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'parent@example.com']);
    $student = Student::factory()->for($user)->create();
    StudentEmail::factory()->for($student)->create(['email' => 'dancer@example.com']);
    StudentEmail::factory()->for($student)->create(['email' => 'guardian@example.com']);

    SendEmailAction::make()->call([
        'data' => [
            'to' => ["student:{$student->id}"],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 3);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('parent@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('dancer@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('guardian@example.com'));
});

it('uses bcc archive copies for non-Textmagic mailers', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.transport', 'log');
    config()->set('mail.mailers.handcrafted.archive_to', 'archive@example.com');

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 2);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && $mail->hasBcc('archive@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('second@example.com')
        && $mail->hasBcc('archive@example.com'));
});

it('can override archive recipients for a send action instance', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.archive_to', '');

    SendEmailAction::make()
        ->archiveTo(['archive@example.com'])
        ->call([
            'data' => [
                'to' => ['first@example.com'],
                'subject' => 'Class update',
                'body' => 'See you soon.',
            ],
        ]);

    Mail::assertQueued(HandcraftedEmail::class, 1);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && $mail->hasBcc('archive@example.com'));
});

it('uses a separate archive email for Textmagic mailers', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.transport', 'textmagic');
    config()->set('mail.mailers.handcrafted.archive_to', 'archive@example.com');

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 3);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && ! $mail->hasBcc('archive@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('second@example.com')
        && ! $mail->hasBcc('archive@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('archive@example.com')
        && ! $mail->hasTo('first@example.com')
        && ! $mail->hasTo('second@example.com'));
});

it('does not queue an archive copy when archive recipients are empty', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.archive_to', '');

    SendEmailAction::make()->call([
        'data' => [
            'to' => ['first@example.com', 'second@example.com'],
            'subject' => 'Class update',
            'body' => 'See you soon.',
        ],
    ]);

    Mail::assertQueued(HandcraftedEmail::class, 2);
});

it('can disable archive copies for a send action instance', function (): void {
    Mail::fake();

    config()->set('mail.mailers.handcrafted.archive_to', 'archive@example.com');

    SendEmailAction::make()
        ->withoutArchiveCopy()
        ->call([
            'data' => [
                'to' => ['first@example.com'],
                'subject' => 'Class update',
                'body' => 'See you soon.',
            ],
        ]);

    Mail::assertQueued(HandcraftedEmail::class, 1);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('first@example.com')
        && ! $mail->hasBcc('archive@example.com'));
});

it('defaults the student resource action to the student name token', function (): void {
    $student = Student::factory()->create();

    livewire(ListStudents::class)
        ->loadTable()
        ->mountAction(TestAction::make('sendEmail')->table($student))
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
        ]);
});

it('keeps direct user resource recipients as email addresses', function (): void {
    $user = User::factory()->create(['email' => 'person@example.com']);

    livewire(ListUsers::class)
        ->loadTable()
        ->mountAction(TestAction::make('sendEmail')->table($user))
        ->assertActionDataSet([
            'to' => ['person@example.com'],
        ]);
});

it('defaults course recipients to assigned students and unassigned purchasers', function (): void {
    $course = Course::factory()->create();
    $assignedUser = User::factory()->create(['email' => 'assigned@example.com']);
    $student = Student::factory()->for($assignedUser)->create();
    $unassignedUser = User::factory()->create(['email' => 'unassigned@example.com']);
    $mismatchedPurchaser = User::factory()->create(['email' => 'mismatched@example.com']);
    $mismatchedStudent = Student::factory()->create();

    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $assignedUser->id,
        'student_id' => $student->id,
    ]);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $unassignedUser->id,
        'student_id' => null,
    ]);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $mismatchedPurchaser->id,
        'student_id' => $mismatchedStudent->id,
    ]);

    livewire(ListCourses::class)
        ->loadTable()
        ->set('activeTab', 'all')
        ->mountAction(TestAction::make('sendEmail')->table($course))
        ->assertActionDataSet([
            'to' => [
                "student:{$student->id}",
                'unassigned@example.com',
                "student:{$mismatchedStudent->id}",
                'mismatched@example.com',
            ],
        ]);
});
