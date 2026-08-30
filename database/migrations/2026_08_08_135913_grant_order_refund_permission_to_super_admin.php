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
        $permission = Permission::findOrCreate('Refund:Order', 'web');

        Role::findOrCreate(Role::SUPER_ADMIN, 'web')->givePermissionTo($permission);
        Role::findOrCreate('owner', 'web')->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', 'Refund:Order')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
