<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;

final class AccessManagerService
{
    public function highestRoleWeight(User $user): ?int
    {
        $weight = $user->roles()->max('weight');

        return $weight === null ? null : (int) $weight;
    }

    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        $actorWeight = $this->highestRoleWeight($actor);

        if ($actorWeight === null) {
            return false;
        }

        $targetWeight = $this->highestRoleWeight($target);

        return $targetWeight === null || $targetWeight < $actorWeight;
    }

    public function canManageRole(User $actor, Role $role): bool
    {
        $actorWeight = $this->highestRoleWeight($actor);

        return $actorWeight !== null
            && ! $role->isSuperAdmin()
            && $role->weight < $actorWeight;
    }

    /** @return Collection<int, Role> */
    public function manageableRoles(User $actor): Collection
    {
        $actorWeight = $this->highestRoleWeight($actor);

        if ($actorWeight === null) {
            return new Collection();
        }

        return Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', Role::SUPER_ADMIN)
            ->where('weight', '<', $actorWeight)
            ->orderByDesc('weight')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Permission> */
    public function manageablePermissions(User $actor): Collection
    {
        /** @var Collection<int, Permission> $permissions */
        $permissions = $actor->getAllPermissions()
            ->where('guard_name', 'web')
            ->sortBy('name')
            ->values();

        return $permissions;
    }

    /**
     * @param  list<int|string>  $roleIds
     * @param  list<int|string>  $permissionIds
     */
    public function syncUserAccess(User $actor, User $target, array $roleIds, array $permissionIds): void
    {
        if (! $this->canManageUser($actor, $target)) {
            throw ValidationException::withMessages([
                'roles' => 'You may only manage access for a lower-ranked user other than yourself.',
            ]);
        }

        $manageableRoles = $this->manageableRoles($actor);
        $selectedRoleIds = $this->validatedIds($roleIds, $manageableRoles->modelKeys(), 'roles');

        $manageablePermissions = $this->manageablePermissions($actor);
        $selectedPermissionIds = $this->validatedIds(
            $permissionIds,
            $manageablePermissions->modelKeys(),
            'permissions',
        );

        DB::transaction(function () use ($target, $selectedRoleIds, $selectedPermissionIds, $manageablePermissions): void {
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            $protectedPermissionIds = $lockedTarget->permissions()
                ->whereNotIn('permissions.id', $manageablePermissions->modelKeys())
                ->pluck('permissions.id')
                ->all();

            $lockedTarget->syncRoles($selectedRoleIds);
            $lockedTarget->syncPermissions(array_values(array_unique([
                ...$selectedPermissionIds,
                ...$protectedPermissionIds,
            ])));
        });
    }

    /**
     * @param  array{name: string, weight: float|int|string, guard_name?: string}  $data
     * @param  list<int|string>  $permissionIds
     */
    public function createRole(User $actor, array $data, array $permissionIds): Role
    {
        $weight = $this->validatedRoleWeight($actor, $data['weight']);
        $this->ensureRoleNameIsAssignable($data['name']);
        $manageablePermissions = $this->manageablePermissions($actor);
        $selectedPermissionIds = $this->validatedIds(
            $permissionIds,
            $manageablePermissions->modelKeys(),
            'permission_ids',
        );

        return DB::transaction(function () use ($data, $weight, $selectedPermissionIds): Role {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'weight' => $weight,
            ]);

            if (! $role instanceof Role) {
                throw new RuntimeException('The configured role model must be '.Role::class.'.');
            }

            $role->syncPermissions($selectedPermissionIds);

            return $role;
        });
    }

    /**
     * @param  array{name: string, weight: float|int|string, guard_name?: string}  $data
     * @param  list<int|string>  $permissionIds
     */
    public function updateRole(User $actor, Role $role, array $data, array $permissionIds): Role
    {
        if (! $this->canManageRole($actor, $role)) {
            throw ValidationException::withMessages([
                'name' => 'You may only modify roles below your own highest role.',
            ]);
        }

        $weight = $this->validatedRoleWeight($actor, $data['weight']);
        $this->ensureRoleNameIsAssignable($data['name']);
        $manageablePermissions = $this->manageablePermissions($actor);
        $selectedPermissionIds = $this->validatedIds(
            $permissionIds,
            $manageablePermissions->modelKeys(),
            'permission_ids',
        );

        return DB::transaction(function () use ($role, $data, $weight, $selectedPermissionIds, $manageablePermissions): Role {
            $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->getKey());
            $protectedPermissionIds = $lockedRole->permissions()
                ->whereNotIn('permissions.id', $manageablePermissions->modelKeys())
                ->pluck('permissions.id')
                ->all();

            $lockedRole->update([
                'name' => $data['name'],
                'guard_name' => 'web',
                'weight' => $weight,
            ]);
            $lockedRole->syncPermissions(array_values(array_unique([
                ...$selectedPermissionIds,
                ...$protectedPermissionIds,
            ])));

            return $lockedRole;
        });
    }

    private function validatedRoleWeight(User $actor, float|int|string $weight): int
    {
        $actorWeight = $this->highestRoleWeight($actor);
        $weight = filter_var($weight, FILTER_VALIDATE_INT);

        if ($actorWeight === null || $weight === false || $weight < 0 || $weight >= $actorWeight) {
            throw ValidationException::withMessages([
                'weight' => 'The role weight must be zero or greater and strictly below your highest role.',
            ]);
        }

        return $weight;
    }

    private function ensureRoleNameIsAssignable(string $name): void
    {
        if ($name === Role::SUPER_ADMIN) {
            throw ValidationException::withMessages([
                'name' => 'The super administrator role can only be provisioned from the command line or seeder.',
            ]);
        }
    }

    /**
     * @param  list<int|string>  $requestedIds
     * @param  list<int|string>  $allowedIds
     * @return list<int|string>
     */
    private function validatedIds(array $requestedIds, array $allowedIds, string $field): array
    {
        $requestedIds = array_values(array_unique($requestedIds));

        if (array_diff($requestedIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                $field => 'You attempted to grant access outside your authorization boundary.',
            ]);
        }

        return $requestedIds;
    }
}
