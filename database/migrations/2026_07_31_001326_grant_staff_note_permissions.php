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
        $permissions = [
            'ViewAny:StaffNote',
            'View:StaffNote',
            'Create:StaffNote',
            'Update:StaffNote',
            'Delete:StaffNote',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate(Role::OWNER, 'web')->givePermissionTo($permissions);
        Role::findOrCreate(Role::SUPER_ADMIN, 'web')->givePermissionTo($permissions);
        Role::findOrCreate(Role::TEACHER, 'web')->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [
                'ViewAny:StaffNote',
                'View:StaffNote',
                'Create:StaffNote',
                'Update:StaffNote',
                'Delete:StaffNote',
            ])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
