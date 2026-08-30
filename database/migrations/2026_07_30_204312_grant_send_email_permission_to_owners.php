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
        $permission = Permission::findOrCreate('Send:Email', 'web');

        foreach ([Role::OWNER, Role::SUPER_ADMIN] as $roleName) {
            Role::findOrCreate($roleName, 'web')
                ->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'Send:Email')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
