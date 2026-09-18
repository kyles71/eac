<?php

declare(strict_types=1);

use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect([
            ReportKey::Payroll->permission(),
            ReportKey::SickLeave->permission(),
            ReportWidgetKey::FinanceOverview->permission(),
        ])->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'));

        foreach ([Role::SUPER_ADMIN, Role::OWNER] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [
                ReportKey::Payroll->permission(),
                ReportKey::SickLeave->permission(),
                ReportWidgetKey::FinanceOverview->permission(),
            ])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
