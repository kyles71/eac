<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\FormUsers\Pages\ListFormUsers;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormVersion;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

it('uses the selected student household as the respondent for a manual student form assignment', function (): void {
    Filament::setCurrentPanel('admin');
    $form = Form::factory()->create(['key' => 'student-waiver']);
    $version = FormVersion::factory()->for($form)->published()->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $otherHousehold = User::factory()->create();

    livewire(ListFormUsers::class)
        ->callAction(TestAction::make('create'), data: [
            'form_id' => $form->id,
            'student_id' => $student->id,
            'respondent_id' => $otherHousehold->id,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    assertDatabaseHas(FormAssignment::class, [
        'form_id' => $form->id,
        'form_version_id' => $version->id,
        'respondent_type' => $household->getMorphClass(),
        'respondent_id' => $household->id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ]);
});
