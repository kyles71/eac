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
        $owner = $this->role('owner', Role::OWNER_WEIGHT);
        $this->role('teacher', Role::TEACHER_WEIGHT);

        $synchronizer->sync();

        $superAdmin->refresh();
        $owner->syncPermissions([
            Permission::findByName('Manage:DashboardAppearance', 'web'),
        ]);

        $this->command?->info('Shield seeding completed.');
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
