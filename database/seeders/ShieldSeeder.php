<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Services\PermissionCatalogSynchronizerService;
use Illuminate\Database\Seeder;
use RuntimeException;
use Spatie\Permission\Models\Permission;

final class ShieldSeeder extends Seeder
{
    public function run(PermissionCatalogSynchronizerService $synchronizer): void
    {
        $superAdmin = $this->role(Role::SUPER_ADMIN, Role::SUPER_ADMIN_WEIGHT);
        $owner = $this->role(Role::OWNER, Role::OWNER_WEIGHT);
        $teacher = $this->role(Role::TEACHER, Role::TEACHER_WEIGHT);

        $synchronizer->sync();

        $superAdmin->refresh();
        $owner->givePermissionTo([
            Permission::findByName('Manage:DashboardAppearance', 'web'),
            Permission::findByName('Send:Email', 'web'),
            Permission::findByName('Update:Event', 'web'),
            Permission::findByName('View:Event', 'web'),
            Permission::findByName('View:Student', 'web'),
            Permission::findByName('ViewAny:Event', 'web'),
            Permission::findByName('ViewAny:Student', 'web'),
        ]);
        $teacher->givePermissionTo([
            Permission::findByName('Update:Event', 'web'),
            Permission::findByName('View:Event', 'web'),
            Permission::findByName('View:Student', 'web'),
            Permission::findByName('ViewAny:Event', 'web'),
            Permission::findByName('ViewAny:Student', 'web'),
        ]);

        $this->command->info('Shield seeding completed.');
    }

    private function role(string $name, int $weight): Role
    {
        $role = Role::findOrCreate($name, 'web');

        if (! $role instanceof Role) {
            throw new RuntimeException('The configured role model must be '.Role::class.'.');
        }

        $role->update(['weight' => $weight]);

        return $role;
    }
}
