<?php

declare(strict_types=1);

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ViewFormUser;
use App\Forms\DefaultFormDefinitions;
use App\Forms\Eac\EacFormContentProvider;
use App\Models\EmergencyContact;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\LegalDocument;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Carbon\CarbonInterface;
use Database\Seeders\ShowcaseParticipationFormSeeder;
use Filament\Facades\Filament;
use Filament\Infolists\Components\IconEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kyle\FilamentFormBuilder\Actions\PublishFormVersion;
use Kyle\FilamentFormBuilder\Actions\SubmitFormResponse;
use Kyle\FilamentFormBuilder\Actions\UpgradeFormAssignmentToVersion;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use Kyle\FilamentFormBuilder\Enums\FormUpdateStrategy;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;
use Kyle\FilamentFormBuilder\Support\FormVersionManager;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
    $policy = LegalDocument::factory()->create(['key' => TextMessageUpdatesPolicy::KEY]);
    $policy->publishVersion('Text Message Updates v1', '<p>Text updates policy</p>');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function createStudentWaiverTestForm(?CarbonInterface $validUntil = null): array
{
    $keys = [
        'medical_conditions' => (string) Str::uuid(),
        'medical_release_consent' => (string) Str::uuid(),
        'medical_release_signed_on' => DefaultFormDefinitions::MedicalReleaseSignedOn,
        'emergency_contacts' => (string) Str::uuid(),
    ];

    $form = Form::factory()->create([
        'name' => 'Student Waiver',
        'key' => 'student-waiver',
        'updates_allowed' => true,
        'update_strategy' => FormUpdateStrategy::Revision,
    ]);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'requires_signature' => true,
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
                    'default_today' => true,
                    'mapping' => 'student_waiver.medical_release_signed_on',
                ],
            ],
            [
                'type' => 'emergency_contacts',
                'data' => [
                    'key' => $keys['emergency_contacts'],
                    'label' => 'Emergency Contacts',
                    'min_items' => 1,
                    'text_message_policy_reference' => app(EacFormContentProvider::class)->currentTextMessageUpdatesPolicyReference(),
                ],
            ],
        ],
    ]);

    app(PublishFormVersion::class)->handle($version, auth()->user());

    if ($validUntil !== null) {
        app(FormVersionManager::class)->deactivate($form, $validUntil);
    }

    return [$form->refresh(), $version->refresh(), $keys];
}

function createStudentWaiverTestAssignment(Form $form, Student $student): FormAssignment
{
    return FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $form->currentVersion?->id ?? $form->versions()->latest('version')->value('id'),
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
                    'phone_number' => '(555) 555-0100',
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
    $emergencyContact = $waiver->emergencyContacts()->sole();

    expect($response->status)->toBe(FormResponseStatus::Submitted)
        ->and($waiver)->toBeInstanceOf(StudentWaiver::class)
        ->and($waiver->medical_conditions)->toBe('Asthma')
        ->and($waiver->medical_release_consent)->toBeTrue()
        ->and($waiver->medical_release_signed_on->toDateString())->toBe('2026-05-24')
        ->and($emergencyContact->relationship)->toBe('Mother')
        ->and($emergencyContact->phone_number)->toBe('(555) 555-0100')
        ->and($emergencyContact->wants_text_updates)->toBeTrue()
        ->and(EmergencyContact::query()->where('student_waiver_id', $waiver->id)->count())->toBe(1);
});

it('shows the configured number of blank emergency contacts on initial page load', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    [$form, , $keys] = createStudentWaiverTestForm();
    $assignment = createStudentWaiverTestAssignment($form, $student);
    $page = livewire(EditFormUser::class, ['record' => $assignment->id])->assertOk();
    $contacts = data_get($page->get('data'), "answers.{$keys['emergency_contacts']}");

    expect($contacts)->toBeArray()
        ->and($contacts)->toHaveCount(2);
});

it('uses a contextual signing page title without an outer form section', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    [$form] = createStudentWaiverTestForm();
    $assignment = createStudentWaiverTestAssignment($form, $student);
    $page = livewire(EditFormUser::class, ['record' => $assignment->id])->assertOk();
    $sections = collect($page->instance()->form->getComponents(withHidden: true))
        ->filter(fn (Component $component): bool => $component instanceof Section);

    expect($page->instance()->getTitle())->toBe('Complete Student Waiver')
        ->and($sections->map(fn (Section $section): ?string => $section->getHeading())->values()->all())
        ->toBe(['Signature']);
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

it('resets waiver and response signature dates to today when starting a revision', function (): void {
    Carbon::setTestNow('2026-07-12 03:30:00 UTC');
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    [$form, , $keys] = createStudentWaiverTestForm();
    $assignment = createStudentWaiverTestAssignment($form, $student);
    app(SubmitFormResponse::class)->handle($assignment, studentWaiverState($keys));

    livewire(EditFormUser::class, ['record' => $assignment->id])
        ->assertSchemaStateSet([
            "answers.{$keys['medical_conditions']}" => 'Asthma',
            "answers.{$keys['medical_release_signed_on']}" => '2026-07-11',
            'signature' => null,
            'date_signed' => '2026-07-11',
        ]);
});

it('does not hydrate responses from an older seasonal version', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    [$form, , $keys] = createStudentWaiverTestForm();
    $assignment = createStudentWaiverTestAssignment($form, $student);
    app(SubmitFormResponse::class)->handle($assignment, studentWaiverState($keys, 'Old season answer'));
    $newKey = (string) Str::uuid();
    $nextVersion = $form->refresh()->createDraftVersion(
        schema: [[
            'type' => 'short_text',
            'data' => ['key' => $newKey, 'label' => 'New season answer'],
        ]],
        requiresSignature: false,
        label: 'Next season',
    );
    app(PublishFormVersion::class)->handle($nextVersion, $user);
    app(UpgradeFormAssignmentToVersion::class)->handle($assignment, $nextVersion);

    livewire(EditFormUser::class, ['record' => $assignment->id])
        ->assertSet("data.answers.{$newKey}", null)
        ->assertSet("data.answers.{$keys['medical_conditions']}", null)
        ->assertSet('data.signature', null)
        ->assertSet('data.date_signed', now((string) config('app.display_timezone'))->toDateString());
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

    $activeForm = Form::factory()->create();
    App\Models\FormVersion::factory()->for($activeForm)->published()->create();
    $activeForm->refresh();
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
        'key' => 'showcase-participation',
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

it('requires signature and signed date for seeded showcase submissions', function (): void {
    $this->seed(ShowcaseParticipationFormSeeder::class);
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    $form = Form::query()->where('key', 'showcase-participation')->firstOrFail();
    app(PublishFormVersion::class)->handle(
        $form->versions()->where('status', FormVersionStatus::Draft)->firstOrFail(),
        $user,
        requireCompletedAgain: true,
        activatesAt: now(),
        deactivatesAt: now()->addMonth(),
    );
    $form->refresh();
    $assignment = createStudentWaiverTestAssignment($form, $student);

    expect(fn () => app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [DefaultFormDefinitions::ShowcaseParticipation => true],
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [DefaultFormDefinitions::ShowcaseParticipation => true],
        'signature' => 'Showcase Parent',
    ]))->toThrow(ValidationException::class);

    $response = app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [DefaultFormDefinitions::ShowcaseParticipation => true],
        'signature' => 'Showcase Parent',
        'date_signed' => '2026-07-15',
    ]);

    expect($response->signature)->toBe('Showcase Parent')
        ->and($response->date_signed?->toDateString())->toBe('2026-07-15');
});

it('renders both consent and non-consent boolean radio answers in completed views', function (bool $consent): void {
    $key = DefaultFormDefinitions::MediaReleaseConsent;
    $form = Form::factory()->create(['updates_allowed' => false]);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'requires_signature' => false,
        'schema' => [[
            'type' => 'boolean_radio',
            'data' => [
                'key' => $key,
                'label' => 'Media Release Consent',
                'true_label' => 'I consent',
                'false_label' => 'I do not consent',
            ],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $assignment = createStudentWaiverTestAssignment($form->refresh(), $student);
    app(SubmitFormResponse::class)->handle($assignment, ['answers' => [$key => $consent]]);
    actingAs($user);
    $path = "latestSubmittedResponse.response_state.answers.{$key}";

    livewire(ViewFormUser::class, ['record' => $assignment->id])
        ->assertSchemaComponentExists(
            $path,
            'infolist',
            fn (Component $component): bool => $component instanceof IconEntry,
        )
        ->assertSchemaComponentStateSet($path, $consent, 'infolist');
})->with([true, false]);

it('renders sanitized instruction formatting in completed views', function (): void {
    $answerKey = (string) Str::uuid();
    $form = Form::factory()->create(['updates_allowed' => false]);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'requires_signature' => false,
        'schema' => [
            [
                'type' => 'text',
                'data' => [
                    'key' => (string) Str::uuid(),
                    'content' => '<strong>Consent to Medical Treatment</strong><script>alert("bad")</script>',
                ],
            ],
            [
                'type' => 'toggle',
                'data' => [
                    'key' => $answerKey,
                    'label' => 'Confirm',
                ],
            ],
        ],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $assignment = createStudentWaiverTestAssignment($form->refresh(), $student);
    app(SubmitFormResponse::class)->handle($assignment, ['answers' => [$answerKey => true]]);
    actingAs($user);
    $page = livewire(ViewFormUser::class, ['record' => $assignment->id])->assertOk();
    $instructions = collect($page->instance()->getSchema('infolist')->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof Text);

    expect($instructions)->toBeInstanceOf(Text::class);

    if (! $instructions instanceof Text) {
        throw new LogicException('The completed-view instructions did not render as text.');
    }

    expect((string) $instructions->getContent())
        ->toContain('<strong>Consent to Medical Treatment</strong>')
        ->not->toContain('<script')
        ->not->toContain('alert');
});
