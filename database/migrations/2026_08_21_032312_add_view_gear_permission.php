<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    public function up(): void
    {
        $viewPermission = Permission::findOrCreate('View:Gear', 'web');
        $viewAnyPermission = Permission::query()
            ->where('name', 'ViewAny:Gear')
            ->where('guard_name', 'web')
            ->first();

        if ($viewAnyPermission !== null) {
            $this->copyAssignments(
                (string) config('permission.table_names.role_has_permissions', 'role_has_permissions'),
                (int) $viewAnyPermission->id,
                (int) $viewPermission->id,
            );
            $this->copyAssignments(
                (string) config('permission.table_names.model_has_permissions', 'model_has_permissions'),
                (int) $viewAnyPermission->id,
                (int) $viewPermission->id,
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', 'View:Gear')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function copyAssignments(string $table, int $fromPermissionId, int $toPermissionId): void
    {
        $permissionPivotKey = (string) (config('permission.column_names.permission_pivot_key') ?: 'permission_id');
        $assignments = DB::table($table)
            ->where($permissionPivotKey, $fromPermissionId)
            ->get();

        foreach ($assignments as $assignment) {
            $attributes = (array) $assignment;
            $attributes[$permissionPivotKey] = $toPermissionId;

            DB::table($table)->insertOrIgnore($attributes);
        }
    }
};
