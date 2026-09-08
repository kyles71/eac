<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\Forms\Pages\EditFormVersion;
use App\Filament\Admin\Resources\Forms\Pages\ViewForm;
use App\Filament\Admin\Resources\Forms\Pages\ViewFormAnalytics;
use App\Filament\Admin\Resources\Forms\Pages\ViewResponse;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Filament\User\Resources\FormUsers\Pages\ViewFormUser;
use App\Filament\User\Resources\Students\Pages\ListStudents;
use App\Filament\User\Resources\Students\Pages\ViewStudent;
use App\Forms\Eac\EacFormContentProvider;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormAssignment;
use App\Models\LegalDocument;
use App\Models\Student;
use App\Models\User;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Tables\Enums\RecordActionsPosition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Kyle\FilamentFormBuilder\Actions\PublishFormVersion;
use Kyle\FilamentFormBuilder\Actions\SubmitFormResponse;
use Kyle\FilamentFormBuilder\Contracts\FormResponseQueryScope;
use Kyle\FilamentFormBuilder\Enums\FormVersionStatus;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\AssignmentsRelationManager;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\ResponsesRelationManager;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\RelationManagers\VersionsRelationManager;
use Kyle\FilamentFormBuilder\Support\FormVersionComparator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('lists the signed-in users assignments from the package table', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);
    $form = Form::factory()->create();
    $version = App\Models\FormVersion::factory()->for($form)->published()->create();
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
        'respondent_type' => $user->getMorphClass(),
        'respondent_id' => $user->id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ]);

    livewire(ListFormUsers::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$assignment]);
});

it('hides breadcrumbs throughout the user panel', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    $student = Student::factory()->create(['user_id' => $user->id]);

    livewire(ListFormUsers::class)->assertOk();
    livewire(ListStudents::class)->assertOk();
    livewire(ViewStudent::class, ['record' => $student->id])->assertOk();

    expect(Filament::getPanel('user')->hasBreadcrumbs())->toBeFalse();
});

it('saves and submits a dynamically compiled user form', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create(['name' => 'Showcase Participation']);
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'requires_signature' => true,
        'schema' => [[
            'type' => 'short_text',
            'data' => [
                'key' => $fieldKey,
                'label' => 'Favorite Color',
                'required' => true,
            ],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());

    $student = Student::factory()->create(['user_id' => $user->id]);
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
        'respondent_type' => $user->getMorphClass(),
        'respondent_id' => $user->id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ]);

    $draftPage = livewire(EditFormUser::class, ['record' => $assignment->id])
        ->assertOk()
        ->assertActionHasLabel('saveDraft', 'Save Draft')
        ->fillForm([
            'answers' => [$fieldKey => 'Blue'],
        ]);

    $savedDataHashBeforeDraft = $draftPage->instance()->savedDataHash;

    $draftPage
        ->callAction('saveDraft')
        ->assertNotified();

    $formActions = $draftPage->instance()->getSchema('content')->getComponent('form-actions');
    $submitAction = $formActions?->getChildSchema()->getAction('save');

    expect($assignment->draftResponse()->exists())->toBeTrue()
        ->and($draftPage->instance()->savedDataHash)->not->toBe($savedDataHashBeforeDraft)
        ->and($submitAction)->not->toBeNull()
        ->and($submitAction?->getLabel())->toBe('Submit Form')
        ->and($submitAction?->getKeyBindings())->toBeNull()
        ->and($submitAction?->isConfirmationRequired())->toBeTrue()
        ->and($draftPage->instance()->getAction('saveDraft')?->getKeyBindings())->toBe(['mod+s']);

    livewire(EditFormUser::class, ['record' => $assignment->id])
        ->assertOk()
        ->fillForm([
            'answers' => [$fieldKey => 'Green'],
            'signature' => 'Taylor Parent',
            'date_signed' => today()->toDateString(),
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $field = $form->fields()->where('key', $fieldKey)->firstOrFail();

    assertDatabaseHas(FormAnswer::class, [
        'form_field_id' => $field->id,
        'value_string' => 'Green',
    ]);

    $viewPage = livewire(ViewFormUser::class, ['record' => $assignment->id])
        ->assertOk()
        ->assertSee('Green');

    $topLevelComponents = $viewPage->instance()->getSchema('infolist')->getComponents();

    expect($viewPage->instance()->getTitle())->toBe('Showcase Participation')
        ->and(collect($topLevelComponents)
            ->filter(fn (Component $component): bool => $component instanceof Section)
            ->map(fn (Section $section): ?string => $section->getHeading())
            ->values()
            ->all())->toBe(['Signature'])
        ->and($topLevelComponents[array_key_last($topLevelComponents)])->toBeInstanceOf(Section::class);
});

it('renders version management, assignments, responses, and analytics', function (): void {
    Filament::setCurrentPanel('admin');

    $conditionKey = (string) Str::uuid();
    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [
            [
                'type' => 'select',
                'data' => [
                    'key' => $conditionKey,
                    'label' => 'Participation choice',
                    'options' => ['yes' => 'Yes', 'no' => 'No'],
                    'column_span' => 1,
                ],
            ],
            [
                'type' => 'short_text',
                'data' => [
                    'key' => $fieldKey,
                    'label' => 'Favorite Color',
                    'required' => true,
                    'column_span' => 1,
                ],
            ],
        ],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());

    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);
    $response = app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$fieldKey => 'Blue'],
    ]);
    $secondAssignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);
    $secondResponse = app(SubmitFormResponse::class)->handle($secondAssignment, [
        'answers' => [$fieldKey => 'Amber'],
    ]);

    livewire(ViewResponse::class, ['record' => $form->id, 'response' => $response->id])
        ->assertSet("responseData.answers.{$fieldKey}", 'Blue')
        ->assertSee($version->versionLabel());

    livewire(ViewFormAnalytics::class, ['record' => $form->id])
        ->assertSet('versionId', $version->id)
        ->assertSee('Responses Counted');
    $versionsManager = livewire(VersionsRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$version])
        ->assertTableColumnExists('current_status')
        ->assertTableColumnExists('respondent_count')
        ->assertActionVisible(TestAction::make('analytics')->table($version))
        ->mountAction(TestAction::make('analytics')->table($version))
        ->assertMountedActionModalSee('Responses Counted')
        ->unmountAction()
        ->assertActionVisible(TestAction::make('preview')->table($version))
        ->mountAction(TestAction::make(CreateAction::class)->table())
        ->assertMountedActionModalSee('Start from scratch')
        ->assertMountedActionModalSee('Current');

    expect($versionsManager->instance()->getMountedAction()?->isModalSlideOver())->toBeFalse();

    $recordActions = $versionsManager->instance()->getTable()->getRecordActions();

    expect($recordActions)->toHaveCount(1)
        ->and($recordActions[0])->toBeInstanceOf(ActionGroup::class)
        ->and($recordActions[0]->getLabel())->toBe('Actions')
        ->and(array_keys($recordActions[0]->getFlatActions()))->toBe([
            'analytics',
            'preview',
            'edit',
            'publish',
            'reschedule',
            'cancelSchedule',
            'compare',
            'delete',
        ]);

    $versionsManager
        ->unmountAction()
        ->callAction(TestAction::make(CreateAction::class)->table(), data: [
            'source' => "version:{$version->id}",
            'label' => 'Copied current version',
        ])
        ->assertNotified();

    expect($form->versions()->where('version', 2)->where('status', FormVersionStatus::Draft)->exists())->toBeTrue();

    $draft = $form->versions()->where('version', 2)->firstOrFail();

    $versionsManager->assertRedirect(FormResource::getUrl('edit-version', [
        'record' => $form,
        'version' => $draft,
    ]));

    expect($draft->schema)->toBe($version->schema)
        ->and($draft->requires_signature)->toBe($version->refresh()->requires_signature)
        ->and($draft->label)->toBe('Copied current version');

    livewire(EditFormVersion::class, ['record' => $form->id, 'version' => $draft->id])
        ->assertOk()
        ->assertSet('data.label', 'Copied current version')
        ->assertSee('Favorite Color')
        ->fillForm([
            'label' => 'Edited copied version',
            'requires_signature' => true,
            'schema' => $draft->schema,
        ])
        ->assertSet('data.requires_signature', true)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($draft->refresh()->label)->toBe('Edited copied version')
        ->and($draft->requires_signature)->toBeTrue();

    livewire(VersionsRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->loadTable()
        ->callAction(TestAction::make('publish')->table($draft), data: [
            'require_completed_again' => false,
        ])
        ->assertNotified();

    livewire(VersionsRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('preview')->table($draft->refresh()))
        ->assertMountedActionModalSee('Favorite Color')
        ->unmountAction()
        ->assertActionVisible(TestAction::make('compare')->table($draft->refresh()))
        ->mountAction(TestAction::make('compare')->table($draft->refresh()))
        ->assertActionMounted(TestAction::make('compare')->table($draft->refresh()))
        ->assertMountedActionModalSee('Version 1')
        ->assertMountedActionModalSee('Version 2');

    $this->view('filament.admin.forms.version-compare', [
        'left' => $version,
        'right' => $draft,
        'comparison' => app(FormVersionComparator::class)->compare($version, $draft),
    ])
        ->assertSee('Version 1')
        ->assertSee('Version 2');

    livewire(AssignmentsRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->assertOk()
        ->loadTable()
        ->assertTableColumnExists('response_status')
        ->assertTableColumnExists('responses_max_updated_at')
        ->assertCanSeeTableRecords([$assignment, $secondAssignment])
        ->filterTable('response_status', 'completed')
        ->assertCanSeeTableRecords([$assignment, $secondAssignment]);

    $responsesManager = livewire(ResponsesRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->loadTable()
        ->assertTableColumnExists('revision_count')
        ->assertActionVisible(TestAction::make('view')->table($response))
        ->assertActionVisible(TestAction::make('view')->table($secondResponse))
        ->assertCanSeeTableRecords([$response, $secondResponse]);

    expect($responsesManager->instance()->getTable()->getRecordActionsPosition())
        ->toBe(RecordActionsPosition::BeforeColumns);
});

it('renders the stored emergency contact relationship on an admin response', function (): void {
    Filament::setCurrentPanel('admin');
    Gate::before(fn (): bool => true);
    actingAs(User::factory()->create());
    $contactsKey = (string) Str::uuid();
    $textPolicy = LegalDocument::factory()->create(['key' => TextMessageUpdatesPolicy::KEY]);
    $textPolicyVersion = $textPolicy->publishVersion('Text Message Updates v1', '<p>Policy</p>');
    $form = Form::factory()->create();
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
        'schema' => [[
            'type' => 'emergency_contacts',
            'data' => [
                'key' => $contactsKey,
                'label' => 'Emergency Contacts',
                'min_items' => 1,
                'default_items' => 1,
                'text_message_policy_reference' => EacFormContentProvider::textMessageUpdatesPolicyReference($textPolicyVersion),
            ],
        ]],
    ]);
    app(PublishFormVersion::class)->handle($version, auth()->user());
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);
    $response = app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [
            $contactsKey => [[
                'name' => 'Amaya Zieme',
                'relationship' => 'Guardian',
                'phone_number' => '(963) 210-0975',
                'email' => 'jamie33@example.org',
                'wants_text_updates' => false,
            ]],
        ],
    ]);

    $page = livewire(ViewResponse::class, ['record' => $form->id, 'response' => $response->id])
        ->assertSet("responseData.answers.{$contactsKey}.0.relationship", 'Guardian');
    $repeater = $page->instance()
        ->getSchema('content')
        ->getComponent(
            findComponentUsing: fn (Component $component): bool => $component instanceof Repeater
                && $component->getName() === "answers.{$contactsKey}",
            withActions: false,
            withHidden: true,
        );

    expect($repeater)->toBeInstanceOf(Repeater::class);

    if (! $repeater instanceof Repeater) {
        throw new LogicException('The emergency contacts response repeater did not render.');
    }

    expect($repeater->getChildSchema()?->getComponents(withHidden: true))
        ->toContainOnlyInstancesOf(Component::class)
        ->and(collect($repeater->getChildSchema()?->getFlatComponents(withHidden: true))
            ->contains(fn (Component $component): bool => $component instanceof TextInput
                && $component->getName() === 'relationship'))
        ->toBeTrue();
});

it('limits response queries to actors with the EAC form-view permission', function (): void {
    $form = Form::factory()->create();
    $version = App\Models\FormVersion::factory()->for($form)->published()->create();
    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);
    $response = app(SubmitFormResponse::class)->handle($assignment, ['answers' => []]);
    $actor = User::factory()->create();
    $scope = app(FormResponseQueryScope::class);

    expect($scope)->toBeInstanceOf(App\Forms\Eac\EacFormResponseQueryScope::class)
        ->and($scope->apply(App\Models\FormResponse::query(), $form, $actor)->count())->toBe(0);

    $actor->givePermissionTo('View:Form');

    expect($scope->apply(App\Models\FormResponse::query(), $form, $actor)->pluck('id')->all())
        ->toBe([$response->id]);
});
