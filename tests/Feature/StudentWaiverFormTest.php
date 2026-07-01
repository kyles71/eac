<?php

declare(strict_types=1);

use App\Enums\FormTypes;
use App\Enums\MedicalWaiverStatus;
use App\Filament\Schemas\StudentWaiver as StudentWaiverSchema;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Filament\User\Resources\FormUsers\Pages\ReviseFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ViewFormUser;
use App\Filament\User\Resources\Students\Pages\ViewStudent;
use App\Models\EmergencyContact;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('can save the student waiver fields from the PDF form', function (): void {
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver->value,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiver = StudentWaiver::factory()->create();
    $formUser = FormUser::factory()
        ->forStudent($student)
        ->unsigned()
        ->create([
            'form_id' => $form->id,
            'user_id' => auth()->id(),
            'responseable_type' => $waiver->getMorphClass(),
            'responseable_id' => $waiver->id,
        ]);

    livewire(EditFormUser::class, ['record' => $formUser->id])
        ->assertOk()
        ->fillForm([
            'responseable' => [
                'student_home_address' => '123 Studio Lane',
                'signer_relationship' => 'Mother',
                'emergency_contacts' => [
                    [
                        'name' => 'Emergency One',
                        'relationship_option' => 'Mother',
                        'relationship' => 'Mother',
                        'phone_number' => '(555) 333-4444',
                        'wants_text_updates' => 1,
                        'email' => 'one@example.com',
                    ],
                    [
                        'name' => 'Emergency Two',
                        'relationship_option' => 'Other',
                        'relationship' => 'Coach',
                        'phone_number' => '(555) 444-5555',
                        'wants_text_updates' => 0,
                        'email' => 'two@example.com',
                    ],
                ],
                'allergies' => 'N/A',
                'medical_conditions' => 'Asthma',
                'past_injuries' => 'N/A',
                'medications' => 'Inhaler',
                'medical_release_consent' => true,
                'behavioral_notes' => 'N/A',
                'medical_release_signed_on' => '2026-05-30',
                'health_safety_policy_consent' => true,
                'health_safety_policy_signed_on' => '2026-05-30',
                'media_release_consent' => 1,
                'media_release_signed_on' => '2026-05-30',
            ],
            'signature' => 'Morgan Dancer',
            'date_signed' => '2026-05-30',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(StudentWaiver::class, [
        'id' => $waiver->id,
        'student_home_address' => '123 Studio Lane',
        'signer_relationship' => 'Mother',
        'allergies' => 'N/A',
        'medical_conditions' => 'Asthma',
        'past_injuries' => 'N/A',
        'medications' => 'Inhaler',
        'medical_release_consent' => 1,
        'behavioral_notes' => 'N/A',
        'medical_release_signed_on' => '2026-05-30 00:00:00',
        'health_safety_policy_consent' => 1,
        'health_safety_policy_signed_on' => '2026-05-30 00:00:00',
        'media_release_consent' => 1,
        'media_release_signed_on' => '2026-05-30 00:00:00',
    ]);

    assertDatabaseHas(FormUser::class, [
        'id' => $formUser->id,
        'signature' => 'Morgan Dancer',
        'date_signed' => '2026-05-30 00:00:00',
    ]);

    assertDatabaseHas('emergency_contacts', [
        'student_waiver_id' => $waiver->id,
        'name' => 'Emergency One',
        'relationship' => 'Mother',
        'phone_number' => '(555) 333-4444',
        'wants_text_updates' => 1,
        'email' => 'one@example.com',
    ]);

    assertDatabaseHas('emergency_contacts', [
        'student_waiver_id' => $waiver->id,
        'name' => 'Emergency Two',
        'relationship' => 'Coach',
        'phone_number' => '(555) 444-5555',
        'wants_text_updates' => 0,
        'email' => 'two@example.com',
    ]);

});

it('defaults waiver signature dates to today', function (): void {
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->addMonth(),
    ]);
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiver = StudentWaiver::query()->create();
    $formUser = FormUser::factory()
        ->forStudent($student)
        ->unsigned()
        ->create([
            'form_id' => $form->id,
            'user_id' => auth()->id(),
            'responseable_type' => $waiver->getMorphClass(),
            'responseable_id' => $waiver->id,
        ]);
    $today = now((string) config('app.display_timezone', config('app.timezone')))->toDateString();

    livewire(EditFormUser::class, ['record' => $formUser->id])
        ->assertFormSet([
            'responseable.medical_release_signed_on' => $today,
            'responseable.health_safety_policy_signed_on' => $today,
            'responseable.media_release_signed_on' => $today,
            'date_signed' => $today,
        ]);
});

it('links student waiver policy copy to published legal documents', function (): void {
    $textMessageUpdatesPolicy = LegalDocument::factory()->create([
        'key' => TextMessageUpdatesPolicy::KEY,
    ]);
    $textMessageUpdatesPolicyVersion = LegalDocumentVersion::factory()->create([
        'legal_document_id' => $textMessageUpdatesPolicy->id,
        'title' => 'Text Message Updates Policy',
    ]);
    $healthSafetyPolicy = LegalDocument::factory()->create([
        'key' => HealthSafetyPolicy::KEY,
    ]);
    $healthSafetyPolicyVersion = LegalDocumentVersion::factory()->create([
        'legal_document_id' => $healthSafetyPolicy->id,
        'title' => 'EAC Health & Safety Policy',
    ]);
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->addMonth(),
    ]);
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiver = StudentWaiver::query()->create();
    $formUser = FormUser::factory()
        ->forStudent($student)
        ->unsigned()
        ->create([
            'form_id' => $form->id,
            'user_id' => auth()->id(),
            'responseable_type' => $waiver->getMorphClass(),
            'responseable_id' => $waiver->id,
        ]);

    $component = livewire(EditFormUser::class, ['record' => $formUser->id]);
    $schema = StudentWaiverSchema::configure(Schema::make($component->instance()), withRelationships: false);
    $emergencyContactsRepeater = $schema->getComponent(
        fn (Component $component): bool => $component instanceof Repeater && $component->getName() === 'emergency_contacts',
        withHidden: true,
    );
    $textMessageUpdatesField = $emergencyContactsRepeater?->getChildSchema()?->getComponent(
        fn (Component $component): bool => $component instanceof Radio && $component->getName() === 'wants_text_updates',
        withHidden: true,
    );
    $healthSafetyPolicyField = $schema->getComponent(
        fn (Component $component): bool => $component instanceof Checkbox && $component->getName() === 'health_safety_policy_consent',
        withHidden: true,
    );

    $textMessageUpdatesHelperHtml = (string) $textMessageUpdatesField?->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString();
    $healthSafetyPolicyHelperHtml = (string) $healthSafetyPolicyField?->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString();

    expect($textMessageUpdatesHelperHtml)
        ->toContain('Click here to view our full Text Message Updates Policy')
        ->toContain(route('legal-documents.versions.show', $textMessageUpdatesPolicyVersion))
        ->toContain('target="_blank"')
        ->and($healthSafetyPolicyHelperHtml)
        ->toContain('View and print the EAC Health &amp; Safety Policy')
        ->toContain(route('legal-documents.versions.show', $healthSafetyPolicyVersion))
        ->toContain('target="_blank"');
});

it('allows unassigned waivers to select only students from the authenticated account', function (): void {
    $form = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $waiver = StudentWaiver::query()->create();
    $formUser = FormUser::factory()->unsigned()->create([
        'form_id' => $form->id,
        'user_id' => auth()->id(),
        'student_id' => null,
        'responseable_type' => $waiver->getMorphClass(),
        'responseable_id' => $waiver->id,
    ]);
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $otherStudent = Student::factory()->create(['user_id' => User::factory()]);

    livewire(EditFormUser::class, ['record' => $formUser->id])
        ->fillForm([
            'responseable' => [
                'userForm' => [
                    'student_id' => $otherStudent->id,
                ],
            ],
            'signature' => 'Parent Name',
        ])
        ->call('save')
        ->assertHasFormErrors(['responseable.userForm.student_id']);

    livewire(EditFormUser::class, ['record' => $formUser->id])
        ->fillForm([
            'responseable' => [
                'userForm' => [
                    'student_id' => $student->id,
                ],
                'student_home_address' => '123 Studio Lane',
                'signer_relationship' => 'Mother',
                'emergency_contacts' => [
                    [
                        'name' => 'Emergency Contact',
                        'relationship_option' => 'Guardian',
                        'relationship' => 'Guardian',
                        'phone_number' => '(555) 333-4444',
                        'wants_text_updates' => 0,
                        'email' => 'emergency@example.com',
                    ],
                ],
                'allergies' => 'N/A',
                'medical_conditions' => 'N/A',
                'past_injuries' => 'N/A',
                'medications' => 'N/A',
                'medical_release_consent' => true,
                'health_safety_policy_consent' => true,
                'media_release_consent' => 0,
            ],
            'signature' => 'Parent Name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($formUser->refresh()->student_id)->toBe($student->id);
});

it('reports missing expired and on file medical waiver statuses', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    expect($student->medicalWaiverStatus())->toBe(MedicalWaiverStatus::Missing);

    $expiredForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->subDay(),
    ]);
    $expiredWaiver = StudentWaiver::factory()->create();
    FormUser::factory()->forStudent($student)->create([
        'form_id' => $expiredForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $expiredWaiver->getMorphClass(),
        'responseable_id' => $expiredWaiver->id,
    ]);

    expect($student->medicalWaiverStatus())->toBe(MedicalWaiverStatus::Expired);

    $activeForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->addMonth(),
    ]);
    $activeWaiver = StudentWaiver::factory()->create();
    FormUser::factory()->forStudent($student)->create([
        'form_id' => $activeForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $activeWaiver->getMorphClass(),
        'responseable_id' => $activeWaiver->id,
    ]);

    expect($student->medicalWaiverStatus())->toBe(MedicalWaiverStatus::OnFile);
});

it('shows medical waiver status and the appropriate student page links', function (): void {
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiver = StudentWaiver::factory()->create(['medical_conditions' => 'Asthma']);
    $formUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $form->id,
        'user_id' => auth()->id(),
        'responseable_type' => $waiver->getMorphClass(),
        'responseable_id' => $waiver->id,
    ]);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->assertSee('On File')
        ->assertSee('View current medical waiver')
        ->assertSee('Update')
        ->assertSee(FormUserResource::getUrl('view', ['record' => $formUser]), false)
        ->assertSee(FormUserResource::getUrl('revise', ['record' => $formUser]), false);

    livewire(ViewFormUser::class, ['record' => $formUser->id])
        ->assertSee('Asthma')
        ->assertSee($waiver->student_home_address);
});

it('shows only the waiver links allowed by status and form state', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $expiredForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'can_update' => true,
        'valid_until' => now()->subDay(),
    ]);
    $expiredWaiver = StudentWaiver::factory()->create();
    $expiredFormUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $expiredForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $expiredWaiver->getMorphClass(),
        'responseable_id' => $expiredWaiver->id,
    ]);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->assertSee('Expired')
        ->assertSee('View current medical waiver')
        ->assertSee(FormUserResource::getUrl('view', ['record' => $expiredFormUser]), false)
        ->assertDontSee('Complete Medical Waiver')
        ->assertDontSee(FormUserResource::getUrl('revise', ['record' => $expiredFormUser]), false);

    $missingStudent = Student::factory()->create(['user_id' => auth()->id()]);
    $activeForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->addMonth(),
    ]);
    $pendingWaiver = StudentWaiver::query()->create();
    $pendingFormUser = FormUser::factory()->forStudent($missingStudent)->unsigned()->create([
        'form_id' => $activeForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $pendingWaiver->getMorphClass(),
        'responseable_id' => $pendingWaiver->id,
    ]);

    livewire(ViewStudent::class, ['record' => $missingStudent->id])
        ->assertSee('Missing')
        ->assertSee('Complete Medical Waiver')
        ->assertSee(FormUserResource::getUrl('edit', ['record' => $pendingFormUser]), false)
        ->assertDontSee('View current medical waiver');
});

it('shows the my forms update action for the owning user without admin form permissions', function (): void {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);
    $form = Form::factory()->create([
        'form_type' => FormTypes::ShowcaseParticipation,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $showcase = ShowcaseParticipation::query()->create();
    $formUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $form->id,
        'user_id' => $user->id,
        'responseable_type' => $showcase->getMorphClass(),
        'responseable_id' => $showcase->id,
    ]);

    $this->actingAs($user);

    livewire(ListFormUsers::class)
        ->loadTable()
        ->assertActionVisible(TestAction::make('update')->table($formUser))
        ->assertSee(FormUserResource::getUrl('edit', ['record' => $formUser]), false);
});

it('uses the newest completed waiver for viewing while updating only the newest valid waiver', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $activeForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $activeWaiver = StudentWaiver::factory()->create();
    $activeFormUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $activeForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $activeWaiver->getMorphClass(),
        'responseable_id' => $activeWaiver->id,
        'updated_at' => now()->subDay(),
    ]);
    $expiredForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->subDay(),
    ]);
    $expiredWaiver = StudentWaiver::factory()->create();
    $expiredFormUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $expiredForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $expiredWaiver->getMorphClass(),
        'responseable_id' => $expiredWaiver->id,
        'updated_at' => now(),
    ]);

    expect($student->medicalWaiverStatus())->toBe(MedicalWaiverStatus::OnFile)
        ->and($student->currentMedicalWaiver()->is($expiredFormUser))->toBeTrue()
        ->and($student->latestValidCompletedMedicalWaiver()->is($activeFormUser))->toBeTrue()
        ->and($activeFormUser->formCanBeUpdated())->toBeTrue()
        ->and($expiredFormUser->formCanBeUpdated())->toBeFalse();
});

it('creates a new medical waiver revision without changing the prior waiver', function (): void {
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiver = StudentWaiver::factory()->create([
        'allergies' => 'Peanuts',
        'signer_relationship' => 'Mother',
        'medical_release_signed_on' => '2025-09-01',
        'health_safety_policy_signed_on' => '2025-09-01',
        'media_release_signed_on' => '2025-09-01',
    ]);
    EmergencyContact::factory()->create([
        'student_waiver_id' => $waiver->id,
        'name' => 'Original Contact',
        'relationship' => 'Guardian',
    ]);
    $formUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $form->id,
        'user_id' => auth()->id(),
        'responseable_type' => $waiver->getMorphClass(),
        'responseable_id' => $waiver->id,
        'signature' => 'Original Signature',
        'date_signed' => '2025-09-01',
    ]);

    livewire(ReviseFormUser::class, ['record' => $formUser->id])
        ->assertSee('Save New Medical Waiver')
        ->assertFormSet(fn (array $data): bool => $data['responseable']['allergies'] === 'Peanuts'
            && $data['responseable']['medical_release_consent'] === true
            && $data['responseable']['health_safety_policy_consent'] === true
            && $data['responseable']['medical_release_signed_on'] === null
            && $data['responseable']['health_safety_policy_signed_on'] === null
            && $data['responseable']['media_release_signed_on'] === null
            && $data['signature'] === null
            && $data['date_signed'] === null)
        ->fillForm([
            'responseable' => [
                'medical_release_consent' => true,
                'medical_release_signed_on' => '2026-06-07',
                'health_safety_policy_consent' => true,
                'health_safety_policy_signed_on' => '2026-06-07',
                'media_release_consent' => 0,
                'media_release_signed_on' => '2026-06-07',
            ],
            'signature' => 'New Signature',
            'date_signed' => '2026-06-07',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Medical waiver updated');

    assertDatabaseCount(FormUser::class, 2);
    assertDatabaseCount(StudentWaiver::class, 2);

    $revision = $student->latestCompletedMedicalWaiver();

    expect($revision)->not->toBeNull()
        ->and($revision->id)->not->toBe($formUser->id)
        ->and($revision->signature)->toBe('New Signature')
        ->and($revision->responseable->allergies)->toBe('Peanuts')
        ->and($revision->responseable->emergencyContacts)->toHaveCount(1)
        ->and($formUser->refresh()->signature)->toBe('Original Signature')
        ->and($formUser->responseable->medical_release_signed_on->toDateString())->toBe('2025-09-01')
        ->and($formUser->formCanBeUpdated())->toBeFalse()
        ->and($revision->formCanBeUpdated())->toBeTrue();

    $this->get(FormUserResource::getUrl('revise', ['record' => $formUser]))
        ->assertForbidden();
});

it('prevents revising another accounts waiver', function (): void {
    $otherUser = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $otherUser->id]);
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $waiver = StudentWaiver::factory()->create();
    $formUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $form->id,
        'user_id' => $otherUser->id,
        'responseable_type' => $waiver->getMorphClass(),
        'responseable_id' => $waiver->id,
    ]);

    $this->get(FormUserResource::getUrl('revise', ['record' => $formUser]))
        ->assertNotFound();
});

it('prevents revising expired disallowed and non-waiver forms', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    foreach ([
        ['form_type' => FormTypes::StudentWaiver, 'can_update' => true, 'valid_until' => now()->subDay()],
        ['form_type' => FormTypes::StudentWaiver, 'can_update' => false, 'valid_until' => now()->addMonth()],
        ['form_type' => FormTypes::ShowcaseParticipation, 'can_update' => true, 'valid_until' => now()->addMonth()],
    ] as $formData) {
        $form = Form::factory()->create($formData);
        $response = $form->form_type === FormTypes::StudentWaiver
            ? StudentWaiver::factory()->create()
            : ShowcaseParticipation::query()->create();
        $formUser = FormUser::factory()->forStudent($student)->create([
            'form_id' => $form->id,
            'user_id' => auth()->id(),
            'responseable_type' => $response->getMorphClass(),
            'responseable_id' => $response->id,
        ]);

        $this->get(FormUserResource::getUrl('revise', ['record' => $formUser]))
            ->assertForbidden();
    }

    $waiverForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $showcase = ShowcaseParticipation::query()->create();
    $malformedWaiver = FormUser::factory()->forStudent($student)->create([
        'form_id' => $waiverForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $showcase->getMorphClass(),
        'responseable_id' => $showcase->id,
    ]);

    $this->get(FormUserResource::getUrl('revise', ['record' => $malformedWaiver]))
        ->assertForbidden();
});

it('keeps non-waiver editing unchanged while completed waivers use revision flow', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiverForm = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->addMonth(),
    ]);
    $waiver = StudentWaiver::factory()->create();
    $waiverFormUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $waiverForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $waiver->getMorphClass(),
        'responseable_id' => $waiver->id,
    ]);
    $showcaseForm = Form::factory()->create([
        'form_type' => FormTypes::ShowcaseParticipation,
        'can_update' => false,
        'valid_until' => now()->subDay(),
    ]);
    $showcase = ShowcaseParticipation::query()->create();
    $showcaseFormUser = FormUser::factory()->forStudent($student)->create([
        'form_id' => $showcaseForm->id,
        'user_id' => auth()->id(),
        'responseable_type' => $showcase->getMorphClass(),
        'responseable_id' => $showcase->id,
    ]);

    $this->get(FormUserResource::getUrl('edit', ['record' => $waiverFormUser]))
        ->assertForbidden();
    $this->get(FormUserResource::getUrl('edit', ['record' => $showcaseFormUser]))
        ->assertOk();
});
