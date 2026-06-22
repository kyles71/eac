<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Role;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class PermissionCatalogSynchronizerService
{
    /** @return list<string> */
    public function desiredPermissions(): array
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return collect(FilamentShield::getEntitiesPermissions() ?? [])
            ->filter(fn (mixed $permission): bool => is_string($permission) && filled($permission))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{created: list<string>, deleted: list<string>, retained: list<string>}
     */
    public function changes(): array
    {
        $desired = collect($this->desiredPermissions());
        $existing = Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name');

        return [
            'created' => $desired->diff($existing)->values()->all(),
            'deleted' => $existing->diff($desired)->sort()->values()->all(),
            'retained' => $desired->intersect($existing)->values()->all(),
        ];
    }

    /**
     * @return array{created: list<string>, deleted: list<string>, retained: list<string>}
     */
    public function sync(): array
    {
        $changes = $this->changes();
        $desired = $this->desiredPermissions();
        $registrar = app(PermissionRegistrar::class);

        $registrar->forgetCachedPermissions();

        DB::transaction(function () use ($desired): void {
            foreach ($desired as $permission) {
                Permission::query()->firstOrCreate([
                    'name' => $permission,
                    'guard_name' => 'web',
                ]);
            }

            Permission::query()
                ->where('guard_name', 'web')
                ->whereNotIn('name', $desired)
                ->delete();

            $superAdmin = Role::findOrCreate(Role::SUPER_ADMIN, 'web');

            if (! $superAdmin instanceof Role) {
                throw new RuntimeException('The configured role model must be '.Role::class.'.');
            }

            $superAdmin->update(['weight' => Role::SUPER_ADMIN_WEIGHT]);
            $superAdmin->syncPermissions(
                Permission::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', $desired)
                    ->get(),
            );
        });

        $registrar->forgetCachedPermissions();

        return $changes;
    }
}
