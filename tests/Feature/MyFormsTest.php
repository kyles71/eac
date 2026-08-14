<?php

declare(strict_types=1);

use App\Enums\FormTypes;
use App\Filament\User\Resources\FormUsers\Pages\EditFormUser;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('defaults new accounts to the pending forms tab with a contextual empty state', function (): void {
    livewire(ListFormUsers::class)
        ->assertSet('activeTab', 'pending')
        ->call('loadTable')
        ->assertSee('No forms to complete')
        ->assertDontSee('No My Forms');
});

it('defaults to the first populated forms tab in attention order', function (string $expectedTab, array $formAttributes, bool $isUnsigned): void {
    $form = Form::factory()->create($formAttributes);
    $formUser = FormUser::factory()
        ->for($form)
        ->for(auth()->user());

    if ($isUnsigned) {
        $formUser = $formUser->unsigned();
    }

    $formUser->create();

    livewire(ListFormUsers::class)
        ->assertSet('activeTab', $expectedTab);
})->with([
    'pending' => ['pending', ['valid_until' => now()->addMonth()], true],
    'completed' => ['completed', ['valid_until' => now()->addMonth()], false],
    'expired' => ['expired', ['valid_until' => now()->subDay()], false],
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

it('can search forms by the assigned student name', function (string $search): void {
    $form = Form::factory()->create([
        'valid_until' => now()->addMonth(),
    ]);
    $matchingStudent = Student::factory()->for(auth()->user())->create([
        'first_name' => 'Avery',
        'last_name' => 'Stone',
    ]);
    $otherStudent = Student::factory()->for(auth()->user())->create([
        'first_name' => 'Jordan',
        'last_name' => 'River',
    ]);
    $matchingForm = FormUser::factory()
        ->for($form)
        ->for(auth()->user())
        ->forStudent($matchingStudent)
        ->unsigned()
        ->create();
    $otherForm = FormUser::factory()
        ->for($form)
        ->for(auth()->user())
        ->forStudent($otherStudent)
        ->unsigned()
        ->create();

    livewire(ListFormUsers::class)
        ->loadTable()
        ->searchTable($search)
        ->assertCanSeeTableRecords([$matchingForm])
        ->assertCanNotSeeTableRecords([$otherForm]);
})->with(['Avery', 'Stone']);

it('uses the form name and response state in the edit-page title', function (bool $isUnsigned, string $expectedTitle): void {
    $form = Form::factory()->create([
        'name' => 'Showcase Participation Form',
        'form_type' => FormTypes::ShowcaseParticipation,
        'can_update' => true,
        'valid_until' => now()->addMonth(),
    ]);
    $formUser = FormUser::factory()
        ->for($form)
        ->for(auth()->user());

    if ($isUnsigned) {
        $formUser = $formUser->unsigned();
    }

    $formUser = $formUser->create();

    livewire(EditFormUser::class, ['record' => $formUser->id])
        ->assertSee($expectedTitle)
        ->assertDontSee('Edit My Form');
})->with([
    'pending response' => [true, 'Complete Showcase Participation Form'],
    'existing response' => [false, 'Update Showcase Participation Form'],
]);
