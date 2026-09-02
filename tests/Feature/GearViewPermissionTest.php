<?php

declare(strict_types=1);

use App\Models\Gear;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

it('authorizes Gear lists and individual records independently', function (): void {
    $gear = Gear::factory()->create();
    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Gear');

    expect($user->can('viewAny', Gear::class))->toBeTrue()
        ->and($user->can('view', $gear))->toBeFalse();

    $user->givePermissionTo('View:Gear');

    expect($user->can('view', $gear))->toBeTrue();
});

it('copies existing Gear list permission assignments to the new record view permission', function (): void {
    $migration = require database_path('migrations/2026_08_21_032312_add_view_gear_permission.php');
    $migration->down();

    $viewAnyPermission = Permission::findByName('ViewAny:Gear', 'web');
    $role = Role::findOrCreate('gear-viewer', 'web');
    $user = User::factory()->create();
    $role->givePermissionTo($viewAnyPermission);
    $user->givePermissionTo($viewAnyPermission);

    $migration->up();

    $viewPermission = Permission::findByName('View:Gear', 'web');

    expect(DB::table('role_has_permissions')
        ->where('permission_id', $viewPermission->id)
        ->where('role_id', $role->id)
        ->exists())->toBeTrue()
        ->and(DB::table('model_has_permissions')
            ->where('permission_id', $viewPermission->id)
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->exists())->toBeTrue();
});
