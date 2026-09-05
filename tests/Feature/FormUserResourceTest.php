<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\FormUsers\Pages\ListFormUsers;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

it('synchronizes the household and student fields on form assignments', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->isSuperAdmin()->create());
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $otherHousehold = User::factory()->create();

    $createPage = livewire(ListFormUsers::class)
        ->mountAction(TestAction::make('create'));
    $schemaName = $createPage->instance()->getMountedActionSchemaName();
    $schema = $createPage->instance()->{$schemaName};
    $statePath = $schema->getStatePath();

    $createPage
        ->set("{$statePath}.student_id", $student->id)
        ->assertActionDataSet(['user_id' => $household->id])
        ->set("{$statePath}.user_id", $otherHousehold->id)
        ->assertActionDataSet(['student_id' => null]);
});
