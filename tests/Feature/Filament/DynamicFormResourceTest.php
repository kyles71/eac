<?php

declare(strict_types=1);

use App\Actions\Forms\PublishFormVersion;
use App\Actions\Forms\SubmitFormResponse;
use App\Enums\FormVersionStatus;
use App\Filament\Admin\Resources\Forms\Pages\ViewForm;
use App\Filament\Admin\Resources\Forms\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Forms\RelationManagers\VersionsRelationManager;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ViewFormUser;
use App\Forms\FormVersionComparator;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormAssignment;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('saves and submits a dynamically compiled user form', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
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

    livewire(EditFormUser::class, ['record' => $assignment->id])
        ->assertOk()
        ->fillForm([
            'answers' => [$fieldKey => 'Blue'],
        ])
        ->callAction('saveDraft')
        ->assertNotified();

    expect($assignment->draftResponse()->exists())->toBeTrue();

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

    livewire(ViewFormUser::class, ['record' => $assignment->id])
        ->assertOk()
        ->assertSee('Green');
});

it('renders version management and searchable typed response columns', function (): void {
    Filament::setCurrentPanel('admin');
    Gate::before(fn (): bool => true);
    actingAs(User::factory()->create());

    $fieldKey = (string) Str::uuid();
    $form = Form::factory()->create();
    $version = $form->versions()->create([
        'version' => 1,
        'status' => FormVersionStatus::Draft,
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

    $assignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);
    app(SubmitFormResponse::class)->handle($assignment, [
        'answers' => [$fieldKey => 'Blue'],
    ]);
    $secondAssignment = FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
    ]);
    app(SubmitFormResponse::class)->handle($secondAssignment, [
        'answers' => [$fieldKey => 'Amber'],
    ]);
    $field = $form->fields()->where('key', $fieldKey)->firstOrFail();

    livewire(VersionsRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$version])
        ->callAction(TestAction::make(CreateAction::class)->table(), data: [
            'requires_signature' => false,
            'schema' => [[
                'type' => 'short_text',
                'data' => [
                    'key' => (string) Str::uuid(),
                    'label' => 'New Draft Question',
                    'required' => false,
                    'column_span' => 1,
                ],
            ]],
        ])
        ->assertNotified();

    expect($form->versions()->where('version', 2)->where('status', FormVersionStatus::Draft)->exists())->toBeTrue();

    $draft = $form->versions()->where('version', 2)->firstOrFail();

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
        ->assertTableColumnExists("answer_{$field->id}")
        ->searchTable('Blue')
        ->assertCanSeeTableRecords([$assignment])
        ->assertCanNotSeeTableRecords([$secondAssignment]);

    livewire(AssignmentsRelationManager::class, [
        'ownerRecord' => $form,
        'pageClass' => ViewForm::class,
    ])
        ->loadTable()
        ->sortTable("answer_{$field->id}")
        ->assertCanSeeTableRecords([$secondAssignment, $assignment], inOrder: true);
});
