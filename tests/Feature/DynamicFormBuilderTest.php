<?php

declare(strict_types=1);

use App\Forms\Eac\EacFormContentProvider;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\FormVersion;
use App\Models\LegalDocument;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kyle\FilamentFormBuilder\Actions\PublishFormVersion;
use Kyle\FilamentFormBuilder\Actions\SaveFormResponseDraft;
use Kyle\FilamentFormBuilder\Actions\SubmitFormResponse;
use Kyle\FilamentFormBuilder\Contracts\FormMappingProvider;
use Kyle\FilamentFormBuilder\Enums\FormAnswerType;
use Kyle\FilamentFormBuilder\Enums\FormResponseStatus;
use Kyle\FilamentFormBuilder\Enums\FormUpdateStrategy;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;
use Kyle\FilamentFormBuilder\Support\FormMapping;
use Kyle\FilamentFormBuilder\Support\FormProjectionTarget;
use Kyle\FilamentFormBuilder\Support\FormSchemaCompiler;
use Kyle\FilamentFormBuilder\Support\FormVersionComparator;
use Kyle\FilamentFormBuilder\Support\FormVersionManager;

use function Pest\Laravel\assertDatabaseHas;

it('publishes a dynamic form and stores submitted answers in typed relational columns', function (): void {
    $textPolicy = LegalDocument::factory()->create(['key' => TextMessageUpdatesPolicy::KEY]);
    $textPolicyVersion = $textPolicy->publishVersion('Text Message Updates v1', '<p>Policy</p>');
    $medicalConditionsKey = (string) Str::uuid();
    $emergencyContactsKey = (string) Str::uuid();
    $form = Form::factory()->create([
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
                'type' => 'section',
                'data' => [
                    'key' => (string) Str::uuid(),
                    'heading' => 'Medical Information',
                    'components' => [
                        [
                            'type' => 'long_text',
                            'data' => [
                                'key' => $medicalConditionsKey,
                                'label' => 'Medical Conditions',
                                'required' => true,
                                'mapping' => 'student_waiver.medical_conditions',
                            ],
                        ],
                        [
                            'type' => 'emergency_contacts',
                            'data' => [
                                'key' => $emergencyContactsKey,
                                'label' => 'Emergency Contacts',
                                'min_items' => 1,
                                'text_message_policy_reference' => EacFormContentProvider::textMessageUpdatesPolicyReference($textPolicyVersion),
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    app(PublishFormVersion::class)->handle($version, auth()->user());

    expect($version->refresh()->status)->toBe(FormVersionStatus::Published)
        ->and($form->fields()->count())->toBe(6);

    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    $assignment = FormAssignment::query()->where([
        'form_id' => $form->id,
        'subject_type' => (new Student())->getMorphClass(),
        'subject_id' => $student->id,
    ])->firstOrFail();
    $state = [
        'answers' => [
            $medicalConditionsKey => 'Asthma',
            $emergencyContactsKey => [
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
        'date_signed' => today()->toDateString(),
    ];

    app(SaveFormResponseDraft::class)->handle($assignment, $state);
    $response = app(SubmitFormResponse::class)->handle($assignment, $state);

    expect($response->status)->toBe(FormResponseStatus::Submitted)
        ->and($assignment->refresh()->isCompleted())->toBeTrue()
        ->and($response->projection)->toBeInstanceOf(StudentWaiver::class)
        ->and($response->projection->medical_conditions)->toBe('Asthma')
        ->and($response->projection->emergencyContacts)->toHaveCount(1);

    $field = $form->fields()->where('key', $medicalConditionsKey)->firstOrFail();

    assertDatabaseHas(FormAnswer::class, [
        'form_response_id' => $response->id,
        'form_field_id' => $field->id,
        'value_text' => 'Asthma',
    ]);
});

it('moves pending assignments to a published version and discards old drafts', function (): void {
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $firstVersion = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Favorite Color', 'required' => true],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($firstVersion, auth()->user());

    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $assignment = FormAssignment::query()->whereMorphedTo('subject', $student)->firstOrFail();
    app(SaveFormResponseDraft::class)->handle($assignment, [
        'answers' => [$fieldKey => 'Blue'],
    ]);

    $secondVersion = $form->versions()->create([
        'version' => 2,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Preferred Color', 'required' => true],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($secondVersion, auth()->user());

    $assignment->refresh();

    expect($assignment->form_version_id)->toBe($secondVersion->id)
        ->and($assignment->responses()->where('status', FormResponseStatus::Draft)->exists())->toBeFalse()
        ->and($assignment->responses()->exists())->toBeFalse();
});

it('keeps completed assignments pinned unless publishing requires completion again', function (): void {
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $firstVersion = FormVersion::factory()->for($form)->published()->create([
        'version' => 1,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Answer', 'required' => true],
        ]],
    ]);
    $form->fields()->create(['key' => $fieldKey, 'answer_type' => 'string']);
    $course = Course::factory()->create();
    $course->forms()->attach($form);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $assignment = FormAssignment::query()->whereMorphedTo('subject', $student)->firstOrFail();
    FormResponse::factory()->create([
        'form_assignment_id' => $assignment->id,
        'form_version_id' => $firstVersion->id,
        'status' => FormResponseStatus::Submitted,
        'submitted_at' => now(),
    ]);
    $secondVersion = $form->versions()->create([
        'version' => 2,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Answer', 'required' => true],
        ]],
    ]);

    app(PublishFormVersion::class)->handle($secondVersion, auth()->user());
    expect($assignment->refresh()->form_version_id)->toBe($firstVersion->id);

    $thirdVersion = $form->versions()->create([
        'version' => 3,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Answer', 'required' => true],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($thirdVersion, auth()->user(), requireCompletedAgain: true);

    expect($assignment->refresh()->form_version_id)->toBe($thirdVersion->id)
        ->and($assignment->isCompleted())->toBeFalse();
});

it('keeps published versions immutable and compares stable fields across versions', function (): void {
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $left = FormVersion::factory()->for($form)->published()->create([
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Old Label'],
        ]],
    ]);
    $right = FormVersion::factory()->for($form)->published()->create([
        'version' => 2,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'New Label'],
        ]],
    ]);

    $comparison = app(FormVersionComparator::class)->compare($left, $right);

    expect($comparison['changed'])->toContain($fieldKey);

    $left->schema = [];
    expect(fn () => $left->save())->toThrow(LogicException::class);
});

it('does not require fields while their visibility condition is not met', function (): void {
    $toggleKey = (string) Str::uuid();
    $detailsKey = (string) Str::uuid();
    $form = Form::factory()->create(['updates_allowed' => true]);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [
            [
                'type' => 'toggle',
                'data' => ['key' => $toggleKey, 'label' => 'Has details'],
            ],
            [
                'type' => 'short_text',
                'data' => [
                    'key' => $detailsKey,
                    'label' => 'Details',
                    'required' => true,
                    'visible_when_key' => $toggleKey,
                    'visible_when_value' => 'true',
                ],
            ],
        ],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);

    app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$toggleKey => false],
    ]);

    expect(fn () => app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$toggleKey => true],
    ]))->toThrow(ValidationException::class);
});

it('uses reactive server-side visibility for dynamic conditional fields', function (): void {
    $toggleKey = (string) Str::uuid();
    $detailsKey = (string) Str::uuid();
    $version = FormVersion::factory()->create([
        'schema' => [
            [
                'type' => 'toggle',
                'data' => [
                    'key' => $toggleKey,
                    'label' => 'Add details?',
                ],
            ],
            [
                'type' => 'short_text',
                'data' => [
                    'key' => $detailsKey,
                    'label' => 'Details',
                    'visible_when_key' => $toggleKey,
                    'visible_when_value' => 'true',
                ],
            ],
        ],
    ]);

    $schema = Schema::make()
        ->components(app(FormSchemaCompiler::class)->components($version));
    [$toggle, $details] = $schema->getComponents(withHidden: true);

    expect($toggle)->toBeInstanceOf(Toggle::class)
        ->and($details)->toBeInstanceOf(TextInput::class)
        ->and($toggle->isLive())->toBeTrue()
        ->and($details->getVisibleJs())->toBeNull();
});

it('does not submit expired assignments', function (): void {
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $version = FormVersion::factory()->for($form)->published()->create([
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Answer'],
        ]],
    ]);
    app(FormVersionManager::class)->deactivate($form, now()->subMinute());
    $form->fields()->create(['key' => $fieldKey, 'answer_type' => 'string']);
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);

    expect(fn () => app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$fieldKey => 'Too late'],
    ]))->toThrow(InvalidArgumentException::class, 'Expired form assignments cannot be submitted.');
});

it('validates scalar strings against their typed answer storage limit', function (): void {
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Answer'],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);

    expect(fn () => app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$fieldKey => str_repeat('a', 256)],
    ]))->toThrow(ValidationException::class);
});

it('rejects unknown builder blocks and visibility rules that do not target earlier scalar fields', function (): void {
    $form = Form::factory()->create();
    $unknown = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'arbitrary_component',
            'data' => ['key' => (string) Str::uuid()],
        ]],
    ]);

    expect(fn () => app(PublishFormVersion::class)->handle($unknown, auth()->user()))
        ->toThrow(InvalidArgumentException::class, 'Unknown form builder block');

    $unknown->delete();
    $dependentKey = (string) Str::uuid();
    $invalidVisibility = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => [
                'key' => (string) Str::uuid(),
                'label' => 'Details',
                'visible_when_key' => $dependentKey,
                'visible_when_value' => 'yes',
            ],
        ]],
    ]);

    expect(fn () => app(PublishFormVersion::class)->handle($invalidVisibility, auth()->user()))
        ->toThrow(InvalidArgumentException::class, 'Visibility rules may only depend on an earlier answer field.');
});

it('allows only one mutable draft and requires replacement field identity for type changes', function (): void {
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $first = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Answer'],
        ]],
    ]);

    expect(fn () => $form->versions()->create([
        'version' => 2,
        'status' => FormVersionStatus::Draft,
        'schema' => [],
    ]))->toThrow(LogicException::class, 'A form may only have one draft version.');

    app(PublishFormVersion::class)->handle($first, auth()->user());
    $second = $form->versions()->create([
        'version' => 2,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'number',
            'data' => ['key' => $fieldKey, 'label' => 'Answer'],
        ]],
    ]);

    expect(fn () => app(PublishFormVersion::class)->handle($second, auth()->user()))
        ->toThrow(InvalidArgumentException::class, 'changed its answer type or mapping');
});

it('replaces the previous submitted response for in-place updates', function (): void {
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create([
        'updates_allowed' => true,
        'update_strategy' => FormUpdateStrategy::InPlace,
    ]);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => ['key' => $fieldKey, 'label' => 'Answer'],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);

    $first = app(SubmitFormResponse::class)->handle($assignment, ['answers' => [$fieldKey => 'First']]);
    $second = app(SubmitFormResponse::class)->handle($assignment, ['answers' => [$fieldKey => 'Second']]);

    expect(FormResponse::query()->where('form_assignment_id', $assignment->id)->count())->toBe(1)
        ->and(FormResponse::query()->find($first->id))->toBeNull()
        ->and($second->revision_of_id)->toBeNull()
        ->and($second->response_state['answers'][$fieldKey])->toBe('Second');
});

it('uses registered mapping providers for generic projection behavior', function (): void {
    config(['filament-form-builder.mapping_providers' => [GenericFormMappingProviderForTest::class]]);

    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create(['key' => 'generic-projection']);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'short_text',
            'data' => [
                'key' => $fieldKey,
                'label' => 'Portable Answer',
                'mapping' => 'generic.answer',
            ],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);

    $response = app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$fieldKey => 'Portable'],
    ]);

    expect($assignment->respondent->refresh()->first_name)->toBe('Portable')
        ->and($response->projection?->is($assignment->respondent))->toBeTrue();
});

final class GenericFormMappingProviderForTest implements FormMappingProvider
{
    public function supports(Kyle\FilamentFormBuilder\Models\Form $form): bool
    {
        return $form->key === 'generic-projection';
    }

    public function mappings(Kyle\FilamentFormBuilder\Models\Form $form): array
    {
        return [
            new FormMapping(
                key: 'generic.answer',
                label: 'Generic Answer',
                answerType: FormAnswerType::String,
                target: 'respondent',
                attribute: 'first_name',
            ),
        ];
    }

    public function targets(Kyle\FilamentFormBuilder\Models\Form $form): array
    {
        return [
            new FormProjectionTarget(
                key: 'respondent',
                label: 'Respondent',
                model: User::class,
                resolve: fn (Kyle\FilamentFormBuilder\Models\FormResponse $response): User => $response->assignment->respondent,
                primary: true,
            ),
        ];
    }
}
