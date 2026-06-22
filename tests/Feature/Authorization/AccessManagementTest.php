<?php

declare(strict_types=1);

use App\Filament\Actions\ManageUserAccessAction;
use App\Filament\Admin\Resources\DashboardQuickLinks\Pages\ListDashboardQuickLinks;
use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Shared\Forms\Components\PermissionCheckboxList;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Services\AccessManagerService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('seeds the role hierarchy', function (): void {
    expect(Role::findByName(Role::SUPER_ADMIN)->weight)->toBe(100)
        ->and(Role::findByName('owner')->weight)->toBe(50)
        ->and(Role::findByName('teacher')->weight)->toBe(10);
});

it('applies direct user permissions to authorization and panel access', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Calendar');

    expect($user->can('ViewAny:Calendar'))->toBeTrue()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('syncs only lower roles and permissions held by the actor while preserving protected direct permissions', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('owner');
    $actor->givePermissionTo(['Manage:UserAccess', 'ViewAny:Calendar']);

    $target = User::factory()->create();
    $target->assignRole('teacher');
    $target->givePermissionTo('Manage:ThemeBuilder');

    app(AccessManagerService::class)->syncUserAccess(
        actor: $actor,
        target: $target,
        roleIds: [Role::findByName('teacher')->id],
        permissionIds: [Permission::findByName('ViewAny:Calendar')->id],
    );

    expect($target->fresh()->getRoleNames()->all())->toBe(['teacher'])
        ->and($target->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe([
            'Manage:ThemeBuilder',
            'ViewAny:Calendar',
        ]);
});

it('denies self peer higher-role and out-of-bound access changes', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('owner');
    $actor->givePermissionTo('Manage:UserAccess');

    $peer = User::factory()->create();
    $peer->assignRole('owner');
    $lowerUser = User::factory()->create();
    $lowerUser->assignRole('teacher');

    $manager = app(AccessManagerService::class);

    expect($manager->canManageUser($actor, $actor))->toBeFalse()
        ->and($manager->canManageUser($actor, $peer))->toBeFalse()
        ->and(fn () => $manager->syncUserAccess(
            $actor,
            $lowerUser,
            [Role::findByName(Role::SUPER_ADMIN)->id],
            [],
        ))->toThrow(ValidationException::class)
        ->and(fn () => $manager->syncUserAccess(
            $actor,
            $lowerUser,
            [Role::findByName('teacher')->id],
            [Permission::findByName('Manage:ThemeBuilder')->id],
        ))->toThrow(ValidationException::class);
});

it('uses the dedicated user access action instead of the ordinary user form', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('owner');
    $actor->givePermissionTo(['ViewAny:User', 'Manage:UserAccess', 'ViewAny:Calendar']);
    $target = User::factory()->create();
    $target->assignRole('teacher');

    $this->actingAs($actor);

    livewire(ListUsers::class)
        ->assertActionExists(TestAction::make(ManageUserAccessAction::class)->table($target))
        ->callAction(TestAction::make(ManageUserAccessAction::class)->table($target), [
            'roles' => [Role::findByName('teacher')->id],
            'permissions' => [Permission::findByName('ViewAny:Calendar')->id],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('User access updated');

    expect($target->fresh()->hasDirectPermission('ViewAny:Calendar'))->toBeTrue();
});

it('offers list and resource-grouped card views for role and direct permissions', function (): void {
    $field = PermissionCheckboxList::make('permissions')->options([
        1 => 'Update:Calendar',
        2 => 'ViewAny:Calendar',
        3 => 'Manage:UserAccess',
    ]);

    expect($field->getGroupedOptions())->toBe([
        'Calendar' => [
            1 => 'Update',
            2 => 'View Any',
        ],
        'User Access' => [
            3 => 'Manage',
        ],
    ]);

    livewire(CreateRole::class)
        ->assertSchemaComponentExists('permission_ids', null, fn ($component): bool => $component instanceof PermissionCheckboxList)
        ->assertSeeHtml("permissionView: 'cards'")
        ->assertSeeHtml('x-data="checkboxListFormComponent({')
        ->assertSee('List')
        ->assertSee('Cards');

    $target = User::factory()->create();

    livewire(ListUsers::class)
        ->loadTable()
        ->mountAction(TestAction::make(ManageUserAccessAction::class)->table($target))
        ->assertSchemaComponentExists(
            'permissions',
            'mountedActionSchema0',
            fn ($component): bool => $component instanceof PermissionCheckboxList
                && $component->getDescriptionAboveSearch() === 'These permissions apply in addition to permissions inherited from roles.',
        );
});

it('enforces role weights permission boundaries and assigned-role deletion', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('owner');
    $actor->givePermissionTo([
        'ViewAny:Calendar',
        'Create:Role',
        'Update:Role',
        'Delete:Role',
    ]);

    $manager = app(AccessManagerService::class);
    $role = $manager->createRole(
        $actor,
        ['name' => 'assistant', 'weight' => 9],
        [Permission::findByName('ViewAny:Calendar')->id],
    );

    expect($role->weight)->toBe(9)
        ->and(fn () => $manager->updateRole(
            $actor,
            $role,
            ['name' => 'assistant', 'weight' => 50],
            [],
        ))->toThrow(ValidationException::class);

    $assignedUser = User::factory()->create();
    $assignedUser->assignRole($role);
    $response = app(RolePolicy::class)->delete($actor, $role);

    expect($response->denied())->toBeTrue()
        ->and($response->message())->toContain('Reassign or remove every user');
});

it('creates hierarchy-limited roles through the app-owned Shield resource', function (): void {
    livewire(CreateRole::class)
        ->fillForm([
            'name' => 'front-desk',
            'weight' => 5,
            'permission_ids' => [Permission::findByName('ViewAny:Calendar')->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(Role::class, [
        'name' => 'front-desk',
        'weight' => 5,
    ]);

    expect(Role::findByName('front-desk')->hasPermissionTo('ViewAny:Calendar'))->toBeTrue();
});

it('authorizes quick-link reordering through update permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['ViewAny:DashboardQuickLink', 'Update:DashboardQuickLink']);
    $this->actingAs($user);

    expect(livewire(ListDashboardQuickLinks::class)->instance()->getTable()->isReorderable())->toBeTrue();

    $user->revokePermissionTo('Update:DashboardQuickLink');

    expect(livewire(ListDashboardQuickLinks::class)->instance()->getTable()->isReorderable())->toBeFalse();
});
