<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Calendars\Pages\ListCalendars;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Calendar;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('preserves system calendars during mixed bulk deletion', function (): void {
    $systemCalendar = Calendar::query()->firstOrCreate(
        ['slug' => Calendar::SLUG_EAC],
        ['name' => 'EAC Calendar'],
    );
    $customCalendar = Calendar::factory()->create(['slug' => 'custom']);

    livewire(ListCalendars::class)
        ->loadTable()
        ->selectTableRecords([$systemCalendar, $customCalendar])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    assertDatabaseHas(Calendar::class, ['id' => $systemCalendar->id]);
    assertDatabaseMissing(Calendar::class, ['id' => $customCalendar->id]);
});

it('preserves equal-ranked users during mixed bulk deletion', function (): void {
    $equalRankUser = User::factory()->create();
    $equalRankUser->assignRole(Role::SUPER_ADMIN);
    $lowerRankUser = User::factory()->create();
    $lowerRankUser->assignRole('teacher');

    livewire(ListUsers::class)
        ->loadTable()
        ->selectTableRecords([$equalRankUser, $lowerRankUser])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    assertDatabaseHas(User::class, ['id' => $equalRankUser->id]);
    assertDatabaseMissing(User::class, ['id' => $lowerRankUser->id]);
});

it('preserves assigned roles during mixed bulk deletion', function (): void {
    $assignedRole = Role::create(['name' => 'assigned', 'guard_name' => 'web', 'weight' => 1]);
    $unassignedRole = Role::create(['name' => 'unassigned', 'guard_name' => 'web', 'weight' => 2]);
    User::factory()->create()->assignRole($assignedRole);

    livewire(ListRoles::class)
        ->loadTable()
        ->selectTableRecords([$assignedRole, $unassignedRole])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    assertDatabaseHas(Role::class, ['id' => $assignedRole->id]);
    assertDatabaseMissing(Role::class, ['id' => $unassignedRole->id]);
});
