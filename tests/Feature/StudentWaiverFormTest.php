<?php

declare(strict_types=1);

use App\Actions\Forms\PublishFormVersion;
use App\Actions\Forms\SubmitFormResponse;
use App\Enums\FormPurpose;
use App\Enums\FormResponseStatus;
use App\Enums\FormUpdateStrategy;
use App\Enums\FormVersionStatus;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Models\EmergencyContact;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

function createStudentWaiverTestForm(?Carbon\CarbonInterface $validUntil = null): array
{
    $keys = [
        'medical_conditions' => (string) Str::uuid(),
        'medical_release_consent' => (string) Str::uuid(),
        'medical_release_signed_on' => (string) Str::uuid(),
        'emergency_contacts' => (string) Str::uuid(),
    ];

    $form = Form::factory()->create([
        'name' => 'Student Waiver',
        'purpose' => FormPurpose::MedicalWaiver,
        'updates_allowed' => true,
        'update_strategy' => FormUpdateStrategy::Revision,
    ]);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'requires_signature' => true,
        'valid_until' => $validUntil,
        'schema' => [
            [
                'type' => 'long_text',
                'data' => [
                    'key' => $keys['medical_conditions'],
                    'label' => 'Medical Conditions',
                    'mapping' => 'student_waiver.medical_conditions',
                ],
            ],
            [
                'type' => 'toggle',
                'data' => [
                    'key' => $keys['medical_release_consent'],
                    'label' => 'Medical Release Consent',
                    'required' => true,
                    'mapping' => 'student_waiver.medical_release_consent',
                ],
            ],
            [
                'type' => 'date',
                'data' => [
                    'key' => $keys['medical_release_signed_on'],
                    'label' => 'Medical Release Signed On',
                    'required' => true,
                    'mapping' => 'student_waiver.medical_release_signed_on',
                ],
            ],
            [
                'type' => 'emergency_contacts',
                'data' => [
                    'key' => $keys['emergency_contacts'],
                    'label' => 'Emergency Contacts',
                    'min_items' => 1,
                ],
            ],
        ],
    ]);

    app(PublishFormVersion::class)->handle($version, auth()->user());

    return [$form->refresh(), $version->refresh(), $keys];
}

function createStudentWaiverTestAssignment(Form $form, Student $student): FormAssignment
{
    return FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $form->currentVersion->id,
        'respondent_type' => $student->user->getMorphClass(),
        'respondent_id' => $student->user_id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ]);
}

function studentWaiverState(array $keys, string $medicalConditions = 'Asthma'): array
{
    return [
        'answers' => [
            $keys['medical_conditions'] => $medicalConditions,
            $keys['medical_release_consent'] => true,
            $keys['medical_release_signed_on'] => '2026-05-24',
            $keys['emergency_contacts'] => [
                [
                    'name' => 'Taylor Parent',
                    'relationship' => 'Mother',
                    'phone_number' => '555-0100',
                    'email' => 'taylor@example.test',
                    'wants_text_updates' => true,
                ],
            ],
        ],
        'signature' => 'Taylor Parent',
        'date_signed' => '2026-05-24',
    ];
}

it('submits a medical waiver from the portal and projects emergency contacts', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    [$form, , $keys] = createStudentWaiverTestForm();
    $assignment = createStudentWaiverTestAssignment($form, $student);

    livewire(EditFormUser::class, ['record' => $assignment->id])
        ->assertOk()
        ->fillForm(studentWaiverState($keys))
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(FormUserResource::getUrl('view', ['record' => $assignment]));

    $response = FormResponse::query()->where('form_assignment_id', $assignment->id)->firstOrFail();
    $waiver = $response->projection;

    expect($response->status)->toBe(FormResponseStatus::Submitted)
        ->and($waiver)->toBeInstanceOf(StudentWaiver::class)
        ->and($waiver->medical_conditions)->toBe('Asthma')
        ->and($waiver->medical_release_consent)->toBeTrue()
        ->and($waiver->medical_release_signed_on->toDateString())->toBe('2026-05-24')
        ->and(EmergencyContact::query()->where('student_waiver_id', $waiver->id)->count())->toBe(1);
});

it('creates medical waiver revisions without overwriting historical projections', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    [$form, , $keys] = createStudentWaiverTestForm();
    $assignment = createStudentWaiverTestAssignment($form, $student);

    $first = app(SubmitFormResponse::class)->handle($assignment, studentWaiverState($keys, 'Peanuts'));
    $second = app(SubmitFormResponse::class)->handle($assignment->refresh(), studentWaiverState($keys, 'Tree nuts'));

    expect(FormResponse::query()->where('form_assignment_id', $assignment->id)->count())->toBe(2)
        ->and($second->revision_of_id)->toBe($first->id)
        ->and($first->projection)->toBeInstanceOf(StudentWaiver::class)
        ->and($second->projection)->toBeInstanceOf(StudentWaiver::class)
        ->and($first->projection->medical_conditions)->toBe('Peanuts')
        ->and($second->projection->medical_conditions)->toBe('Tree nuts')
        ->and($student->latestValidCompletedMedicalWaiver()->is($assignment))->toBeTrue();
});

it('blocks expired and non-owned waiver assignments from portal editing', function (): void {
    $owner = User::factory()->create();
    actingAs($owner);
    $student = Student::factory()->create(['user_id' => $owner->id]);
    [$form, , $keys] = createStudentWaiverTestForm(now()->subDay());
    $assignment = createStudentWaiverTestAssignment($form, $student);

    expect(FormUserResource::canEdit($assignment))->toBeFalse();
    expect($this->get(FormUserResource::getUrl('edit', ['record' => $assignment]))->getStatusCode())
        ->toBeIn([403, 404]);

    [$activeForm] = createStudentWaiverTestForm();
    $otherUser = User::factory()->create();
    $otherStudent = Student::factory()->create(['user_id' => $otherUser->id]);
    $otherAssignment = createStudentWaiverTestAssignment($activeForm, $otherStudent);

    expect(FormUserResource::canEdit($otherAssignment))->toBeFalse();
    expect($this->get(FormUserResource::getUrl('edit', ['record' => $otherAssignment]))->getStatusCode())
        ->toBeIn([403, 404]);

    expect(fn () => app(SubmitFormResponse::class)->handle($assignment, studentWaiverState($keys)))
        ->toThrow(InvalidArgumentException::class, 'Expired form assignments cannot be submitted.');
});

it('projects showcase participation through the generic mapping contract', function (): void {
    $key = (string) Str::uuid();
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    $form = Form::factory()->create([
        'name' => 'Showcase Participation',
        'purpose' => FormPurpose::ShowcaseParticipation,
    ]);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'toggle',
            'data' => [
                'key' => $key,
                'label' => 'Participating?',
                'mapping' => 'showcase_participation.is_participating',
            ],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($version, $user);
    $assignment = createStudentWaiverTestAssignment($form->refresh(), $student);

    $response = app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$key => true],
    ]);

    expect($response->projection)->toBeInstanceOf(ShowcaseParticipation::class)
        ->and($response->projection->is_participating)->toBeTrue();
});
