<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('View:Updates', 'web');

        Role::findOrCreate(Role::SUPER_ADMIN, 'web')->givePermissionTo($permission);
        Role::findOrCreate('owner', 'web')->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', 'View:Updates')
            ->where('guard_name', 'web')
            ->first();

        if ($permission !== null) {
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
