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

        Role::findOrCreate(Role::TEACHER, 'web')
            ->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::findOrCreate(Role::TEACHER, 'web')
            ->revokePermissionTo('Send:Email');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
