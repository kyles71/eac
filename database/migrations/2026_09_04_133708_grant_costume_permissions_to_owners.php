<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as PermissionRole;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private const array PERMISSIONS = [
        'ViewAny:Costume',
        'View:Costume',
        'Create:Costume',
        'Update:Costume',
        'Delete:Costume',
        'DeleteAny:Costume',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));

        PermissionRole::findOrCreate(Role::OWNER, 'web')->givePermissionTo($permissions);
        PermissionRole::findOrCreate(Role::SUPER_ADMIN, 'web')->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
