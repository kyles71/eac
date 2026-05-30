<?php

declare(strict_types=1);

use App\Enums\FormTypes;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;
use App\Models\StudentWaiver;
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
                'student_name' => 'Avery Dancer',
                'student_birth_date' => '2015-04-12',
                'student_home_address' => '123 Studio Lane',
                'student_email' => 'avery@example.com',
                'signer_name' => 'Morgan Dancer',
                'signer_relationship' => 'Mother',
                'contact_phone' => '(555) 111-2222',
                'wants_text_updates' => 1,
                'text_update_phone' => '(555) 222-3333',
                'contact_email' => 'morgan@example.com',
                'emergency_contacts' => [
                    [
                        'name' => 'Emergency One',
                        'relationship' => 'Aunt',
                        'phone_number' => '(555) 333-4444',
                        'email' => 'one@example.com',
                    ],
                    [
                        'name' => 'Emergency Two',
                        'relationship' => 'Uncle',
                        'phone_number' => '(555) 444-5555',
                        'email' => 'two@example.com',
                    ],
                ],
                'heard_about' => 'From a friend',
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
        'student_name' => 'Avery Dancer',
        'student_birth_date' => '2015-04-12 00:00:00',
        'student_home_address' => '123 Studio Lane',
        'student_email' => 'avery@example.com',
        'signer_name' => 'Morgan Dancer',
        'signer_relationship' => 'Mother',
        'contact_phone' => '(555) 111-2222',
        'wants_text_updates' => 1,
        'text_update_phone' => '(555) 222-3333',
        'contact_email' => 'morgan@example.com',
        'heard_about' => 'From a friend',
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
        'relationship' => 'Aunt',
        'phone_number' => '(555) 333-4444',
        'email' => 'one@example.com',
    ]);

    assertDatabaseHas('emergency_contacts', [
        'student_waiver_id' => $waiver->id,
        'name' => 'Emergency Two',
        'relationship' => 'Uncle',
        'phone_number' => '(555) 444-5555',
        'email' => 'two@example.com',
    ]);
});
