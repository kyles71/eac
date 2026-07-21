<?php

declare(strict_types=1);

use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormVersion;
use App\Models\Student;
use App\Models\User;
use Filament\Facades\Filament;
use Kyle\FilamentFormBuilder\Actions\SubmitFormResponse;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

function createMyFormsTestAssignment(Form $form, FormVersion $version, User $user): FormAssignment
{
    $student = Student::factory()->create(['user_id' => $user->id]);

    return FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
        'respondent_type' => $user->getMorphClass(),
        'respondent_id' => $user->id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ]);
}

it('defaults new accounts to the pending forms tab with a contextual empty state', function (): void {
    livewire(ListFormUsers::class)
        ->assertSet('activeTab', 'pending')
        ->call('loadTable')
        ->assertSee('No forms to complete')
        ->assertDontSee('No My Forms');
});

it('defaults to the first populated forms tab in attention order', function (string $expectedTab, bool $isCompleted, bool $isExpired): void {
    /** @var User $user */
    $user = auth()->user();
    $form = Form::factory()->create();
    $version = FormVersion::factory()
        ->for($form)
        ->published()
        ->create();
    $assignment = createMyFormsTestAssignment($form, $version, $user);

    if ($isCompleted) {
        app(SubmitFormResponse::class)->handle($assignment, ['answers' => []]);
    }

    if ($isExpired) {
        FormVersion::factory()
            ->for($form)
            ->published()
            ->create(['version' => 2]);
    }

    livewire(ListFormUsers::class)
        ->assertSet('activeTab', $expectedTab);
})->with([
    'pending' => ['pending', false, false],
    'completed' => ['completed', true, false],
    'expired' => ['expired', false, true],
]);

it('uses tab-specific empty-state copy', function (string $tab, string $heading): void {
    livewire(ListFormUsers::class)
        ->set('activeTab', $tab)
        ->call('loadTable')
        ->assertSee($heading);
})->with([
    ['pending', 'No forms to complete'],
    ['completed', 'No completed forms'],
    ['expired', 'No expired forms'],
]);

it('uses the form name and response state in the edit-page title', function (bool $isCompleted, string $expectedTitle): void {
    /** @var User $user */
    $user = auth()->user();
    $form = Form::factory()->create([
        'name' => 'Showcase Participation Form',
        'updates_allowed' => true,
    ]);
    $version = FormVersion::factory()
        ->for($form)
        ->published()
        ->create();
    $assignment = createMyFormsTestAssignment($form, $version, $user);

    if ($isCompleted) {
        app(SubmitFormResponse::class)->handle($assignment, ['answers' => []]);
    }

    livewire(EditFormUser::class, ['record' => $assignment->id])
        ->assertSee($expectedTitle)
        ->assertDontSee('Edit My Form');
})->with([
    'pending response' => [false, 'Complete Showcase Participation Form'],
    'existing response' => [true, 'Update Showcase Participation Form'],
]);
