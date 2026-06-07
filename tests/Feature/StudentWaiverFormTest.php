<?php

declare(strict_types=1);

use App\Enums\FormTypes;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use Filament\Facades\Filament;

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
