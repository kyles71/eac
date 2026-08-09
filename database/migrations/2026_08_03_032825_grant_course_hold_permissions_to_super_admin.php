<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    /** @var list<string> */
    private const array PERMISSIONS = [
        'ViewAny:CourseHold',
        'View:CourseHold',
        'Create:CourseHold',
        'Update:CourseHold',
    ];

    public function up(): void
    {
        $superAdmin = Role::findOrCreate(Role::SUPER_ADMIN, 'web');
        $owner = Role::findOrCreate('owner', 'web');

        foreach (self::PERMISSIONS as $permissionName) {
            $superAdmin->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
            $owner->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
