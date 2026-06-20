<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    public function up(): void
    {
        $permissionTable = (string) config('permission.table_names.permissions');
        $roleTable = (string) config('permission.table_names.roles');
        $rolePermissionTable = (string) config('permission.table_names.role_has_permissions');
        $now = now();

        DB::table($permissionTable)->insertOrIgnore([
            'name' => 'Cancel:Event',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table($permissionTable)
            ->where('name', 'Cancel:Event')
            ->where('guard_name', 'web')
            ->value('id');
        $superAdminRoleId = DB::table($roleTable)
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId !== null && $superAdminRoleId !== null) {
            DB::table($rolePermissionTable)->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $superAdminRoleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionTable = (string) config('permission.table_names.permissions');

        DB::table($permissionTable)
            ->where('name', 'Cancel:Event')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
