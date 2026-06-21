<?php

declare(strict_types=1);

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class ShieldSeeder extends Seeder
{
    public static function makeDirectPermissions(string $directPermissions): void
    {
        if (blank($permissions = json_decode($directPermissions, true))) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        foreach ($permissions as $permission) {
            if ($permissionModel::whereName($permission['name'])->doesntExist()) {
                $permissionModel::create([
                    'name' => $permission['name'],
                    'guard_name' => $permission['guard_name'],
                ]);
            }
        }
    }

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $tenants = '[]';
        $users = '[]';
        $userTenantPivot = '[]';
        $rolesWithPermissions = '[
                                    {"name":"super_admin","guard_name":"web","permissions":["Manage:DashboardAppearance","ViewAny:Role","View:Role","Create:Role","Update:Role","Delete:Role","DeleteAny:Role","Restore:Role","ForceDelete:Role","ForceDeleteAny:Role","RestoreAny:Role","Replicate:Role","Reorder:Role","ViewAny:Calendar","View:Calendar","Create:Calendar","Update:Calendar","Delete:Calendar","DeleteAny:Calendar","Restore:Calendar","ForceDelete:Calendar","ForceDeleteAny:Calendar","RestoreAny:Calendar","Replicate:Calendar","Reorder:Calendar","ViewAny:Holiday","View:Holiday","Create:Holiday","Update:Holiday","Delete:Holiday","DeleteAny:Holiday","Restore:Holiday","ForceDelete:Holiday","ForceDeleteAny:Holiday","RestoreAny:Holiday","Replicate:Holiday","Reorder:Holiday","ViewAny:Costume","View:Costume","Create:Costume","Update:Costume","Delete:Costume","DeleteAny:Costume","Restore:Costume","ForceDelete:Costume","ForceDeleteAny:Costume","RestoreAny:Costume","Replicate:Costume","Reorder:Costume","ViewAny:Course","View:Course","Create:Course","Update:Course","Delete:Course","DeleteAny:Course","Restore:Course","ForceDelete:Course","ForceDeleteAny:Course","RestoreAny:Course","Replicate:Course","Reorder:Course","ViewAny:DiscountCode","View:DiscountCode","Create:DiscountCode","Update:DiscountCode","Delete:DiscountCode","DeleteAny:DiscountCode","Restore:DiscountCode","ForceDelete:DiscountCode","ForceDeleteAny:DiscountCode","RestoreAny:DiscountCode","Replicate:DiscountCode","Reorder:DiscountCode","ViewAny:Enrollment","View:Enrollment","Create:Enrollment","Update:Enrollment","Delete:Enrollment","DeleteAny:Enrollment","Restore:Enrollment","ForceDelete:Enrollment","ForceDeleteAny:Enrollment","RestoreAny:Enrollment","Replicate:Enrollment","Reorder:Enrollment","ViewAny:Event","View:Event","Create:Event","Update:Event","Delete:Event","DeleteAny:Event","Restore:Event","ForceDelete:Event","ForceDeleteAny:Event","RestoreAny:Event","Replicate:Event","Reorder:Event","ViewAny:FormUser","View:FormUser","Create:FormUser","Update:FormUser","Delete:FormUser","DeleteAny:FormUser","Restore:FormUser","ForceDelete:FormUser","ForceDeleteAny:FormUser","RestoreAny:FormUser","Replicate:FormUser","Reorder:FormUser","ViewAny:Form","View:Form","Create:Form","Update:Form","Delete:Form","DeleteAny:Form","Restore:Form","ForceDelete:Form","ForceDeleteAny:Form","RestoreAny:Form","Replicate:Form","Reorder:Form","ViewAny:GiftCardType","View:GiftCardType","Create:GiftCardType","Update:GiftCardType","Delete:GiftCardType","DeleteAny:GiftCardType","Restore:GiftCardType","ForceDelete:GiftCardType","ForceDeleteAny:GiftCardType","RestoreAny:GiftCardType","Replicate:GiftCardType","Reorder:GiftCardType","ViewAny:LegalDocument","View:LegalDocument","Create:LegalDocument","Update:LegalDocument","ViewAny:GiftCard","View:GiftCard","Create:GiftCard","Update:GiftCard","Delete:GiftCard","DeleteAny:GiftCard","Restore:GiftCard","ForceDelete:GiftCard","ForceDeleteAny:GiftCard","RestoreAny:GiftCard","Replicate:GiftCard","Reorder:GiftCard","ViewAny:Order","View:Order","Create:Order","Update:Order","Delete:Order","DeleteAny:Order","Restore:Order","ForceDelete:Order","ForceDeleteAny:Order","RestoreAny:Order","Replicate:Order","Reorder:Order","ViewAny:PaymentPlanTemplate","View:PaymentPlanTemplate","Create:PaymentPlanTemplate","Update:PaymentPlanTemplate","Delete:PaymentPlanTemplate","DeleteAny:PaymentPlanTemplate","Restore:PaymentPlanTemplate","ForceDelete:PaymentPlanTemplate","ForceDeleteAny:PaymentPlanTemplate","RestoreAny:PaymentPlanTemplate","Replicate:PaymentPlanTemplate","Reorder:PaymentPlanTemplate","ViewAny:PaymentPlan","View:PaymentPlan","Create:PaymentPlan","Update:PaymentPlan","Delete:PaymentPlan","DeleteAny:PaymentPlan","Restore:PaymentPlan","ForceDelete:PaymentPlan","ForceDeleteAny:PaymentPlan","RestoreAny:PaymentPlan","Replicate:PaymentPlan","Reorder:PaymentPlan","ViewAny:Product","View:Product","Create:Product","Update:Product","Delete:Product","DeleteAny:Product","Restore:Product","ForceDelete:Product","ForceDeleteAny:Product","RestoreAny:Product","Replicate:Product","Reorder:Product","ViewAny:Student","View:Student","Create:Student","Update:Student","Delete:Student","DeleteAny:Student","Restore:Student","ForceDelete:Student","ForceDeleteAny:Student","RestoreAny:Student","Replicate:Student","Reorder:Student","ViewAny:User","View:User","Create:User","Update:User","Delete:User","DeleteAny:User","Restore:User","ForceDelete:User","ForceDeleteAny:User","RestoreAny:User","Replicate:User","Reorder:User","ViewAny:DashboardMessage","View:DashboardMessage","Create:DashboardMessage","Update:DashboardMessage","Delete:DashboardMessage","DeleteAny:DashboardMessage","ViewAny:DashboardQuickLink","View:DashboardQuickLink","Create:DashboardQuickLink","Update:DashboardQuickLink","Delete:DashboardQuickLink","DeleteAny:DashboardQuickLink","Reorder:DashboardQuickLink","View:CalendarWidget"]},
                                    {"name":"owner","guard_name":"web","permissions":["Manage:DashboardAppearance"]},
                                    {"name":"teacher","guard_name":"web","permissions":[]}
                                ]';
        $rolesWithPermissions = self::withCompetitionPermissions($rolesWithPermissions);
        $rolesWithPermissions = self::withEventCancellationPermission($rolesWithPermissions);
        $directPermissions = '[
                                {"name":"Manage:MailManager","guard_name":"web"}
                             ]';

        // 1. Seed tenants first (if present)
        if (! blank($tenants) && $tenants !== '[]') {
            self::seedTenants($tenants);
        }

        // 2. Seed roles with permissions
        self::makeRolesWithPermissions($rolesWithPermissions);

        // 3. Seed direct permissions
        self::makeDirectPermissions($directPermissions);

        // 4. Seed users with their roles/permissions (if present)
        if (! blank($users) && $users !== '[]') {
            self::seedUsers($users);
        }

        // 5. Seed user-tenant pivot (if present)
        if (! blank($userTenantPivot) && $userTenantPivot !== '[]') {
            self::seedUserTenantPivot($userTenantPivot);
        }

        $this->command->info('Shield Seeding Completed.');
    }

    protected static function seedTenants(string $tenants): void
    {
        if (blank($tenantData = json_decode($tenants, true))) {
            return;
        }

        $tenantModel = '';
        if (blank($tenantModel)) {
            return;
        }

        foreach ($tenantData as $tenant) {
            $tenantModel::firstOrCreate(
                ['id' => $tenant['id']],
                $tenant
            );
        }
    }

    protected static function seedUsers(string $users): void
    {
        if (blank($userData = json_decode($users, true))) {
            return;
        }

        $userModel = 'App\Models\User';
        $tenancyEnabled = false;

        foreach ($userData as $data) {
            // Extract role/permission data before creating user
            $roles = $data['roles'] ?? [];
            $permissions = $data['permissions'] ?? [];
            $tenantRoles = $data['tenant_roles'] ?? [];
            $tenantPermissions = $data['tenant_permissions'] ?? [];
            unset($data['roles'], $data['permissions'], $data['tenant_roles'], $data['tenant_permissions']);

            $user = $userModel::firstOrCreate(
                ['email' => $data['email']],
                $data
            );

            // Handle tenancy mode - sync roles/permissions per tenant
            if ($tenancyEnabled && (! empty($tenantRoles) || ! empty($tenantPermissions))) {
                foreach ($tenantRoles as $tenantId => $roleNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncRoles($roleNames);
                }

                foreach ($tenantPermissions as $tenantId => $permissionNames) {
                    $contextId = $tenantId === '_global' ? null : $tenantId;
                    setPermissionsTeamId($contextId);
                    $user->syncPermissions($permissionNames);
                }
            } else {
                // Non-tenancy mode
                if (! empty($roles)) {
                    $user->syncRoles($roles);
                }

                if (! empty($permissions)) {
                    $user->syncPermissions($permissions);
                }
            }
        }
    }

    protected static function seedUserTenantPivot(string $pivot): void
    {
        if (blank($pivotData = json_decode($pivot, true))) {
            return;
        }

        $pivotTable = '';
        if (blank($pivotTable)) {
            return;
        }

        foreach ($pivotData as $row) {
            $uniqueKeys = [];

            if (isset($row['user_id'])) {
                $uniqueKeys['user_id'] = $row['user_id'];
            }

            $tenantForeignKey = 'team_id';
            if (! blank($tenantForeignKey) && isset($row[$tenantForeignKey])) {
                $uniqueKeys[$tenantForeignKey] = $row[$tenantForeignKey];
            }

            if (! empty($uniqueKeys)) {
                DB::table($pivotTable)->updateOrInsert($uniqueKeys, $row);
            }
        }
    }

    protected static function makeRolesWithPermissions(string $rolesWithPermissions): void
    {
        if (blank($rolePlusPermissions = json_decode($rolesWithPermissions, true))) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Model $roleModel */
        $roleModel = Utils::getRoleModel();
        /** @var \Illuminate\Database\Eloquent\Model $permissionModel */
        $permissionModel = Utils::getPermissionModel();

        $tenancyEnabled = false;
        $teamForeignKey = 'team_id';

        foreach ($rolePlusPermissions as $rolePlusPermission) {
            $tenantId = $rolePlusPermission[$teamForeignKey] ?? null;

            // Set tenant context for role creation and permission sync
            if ($tenancyEnabled) {
                setPermissionsTeamId($tenantId);
            }

            $roleData = [
                'name' => $rolePlusPermission['name'],
                'guard_name' => $rolePlusPermission['guard_name'],
            ];

            // Include tenant ID in role data (can be null for global roles)
            if ($tenancyEnabled && ! blank($teamForeignKey)) {
                $roleData[$teamForeignKey] = $tenantId;
            }

            $role = $roleModel::firstOrCreate($roleData);

            if (! blank($rolePlusPermission['permissions'])) {
                $permissionModels = collect($rolePlusPermission['permissions'])
                    ->map(fn ($permission) => $permissionModel::firstOrCreate([
                        'name' => $permission,
                        'guard_name' => $rolePlusPermission['guard_name'],
                    ]))
                    ->all();

                $role->syncPermissions($permissionModels);
            }
        }
    }

    private static function withCompetitionPermissions(string $rolesWithPermissions): string
    {
        $roles = json_decode($rolesWithPermissions, true, flags: JSON_THROW_ON_ERROR);
        $competitionPermissions = collect(['CompetitionSeason', 'CompetitionTeam'])
            ->flatMap(fn (string $resource): array => [
                "ViewAny:{$resource}",
                "View:{$resource}",
                "Create:{$resource}",
                "Update:{$resource}",
                "Delete:{$resource}",
                "DeleteAny:{$resource}",
                "Restore:{$resource}",
                "ForceDelete:{$resource}",
                "ForceDeleteAny:{$resource}",
                "RestoreAny:{$resource}",
                "Replicate:{$resource}",
                "Reorder:{$resource}",
            ])
            ->all();

        foreach ($roles as &$role) {
            if ($role['name'] === 'super_admin') {
                $role['permissions'] = array_values(array_unique([
                    ...$role['permissions'],
                    ...$competitionPermissions,
                ]));
            }
        }
        unset($role);

        return json_encode($roles, JSON_THROW_ON_ERROR);
    }

    private static function withEventCancellationPermission(string $rolesWithPermissions): string
    {
        $roles = json_decode($rolesWithPermissions, true, flags: JSON_THROW_ON_ERROR);

        foreach ($roles as &$role) {
            if ($role['name'] === 'super_admin') {
                $role['permissions'] = array_values(array_unique([
                    ...$role['permissions'],
                    'Cancel:Event',
                ]));
            }
        }
        unset($role);

        return json_encode($roles, JSON_THROW_ON_ERROR);
    }
}
