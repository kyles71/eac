<?php

declare(strict_types=1);

use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $reportPermissions = collect(ReportKey::cases())
            ->map(fn (ReportKey $report): PermissionContract => Permission::findOrCreate($report->permission(), 'web'));
        $widgetPermissions = collect(ReportWidgetKey::cases())
            ->filter(fn (ReportWidgetKey $widget): bool => $widget->hasDedicatedPermission())
            ->map(fn (ReportWidgetKey $widget): PermissionContract => Permission::findOrCreate($widget->permission(), 'web'));
        $teacherReportPermissions = collect(ReportKey::cases())
            ->filter(fn (ReportKey $report): bool => $report->availableToTeachersByDefault())
            ->map(fn (ReportKey $report): PermissionContract => Permission::findByName($report->permission(), 'web'));
        $teacherWidgetPermissions = collect(ReportWidgetKey::cases())
            ->filter(fn (ReportWidgetKey $widget): bool => $widget->hasDedicatedPermission())
            ->filter(fn (ReportWidgetKey $widget): bool => $widget->availableToTeachersByDefault())
            ->map(fn (ReportWidgetKey $widget): PermissionContract => Permission::findByName($widget->permission(), 'web'));

        foreach ([Role::SUPER_ADMIN, Role::OWNER] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo([
                ...$reportPermissions->all(),
                ...$widgetPermissions->all(),
            ]);
        }

        Role::findOrCreate(Role::TEACHER, 'web')->givePermissionTo([
            ...$teacherReportPermissions->all(),
            ...$teacherWidgetPermissions->all(),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [
                ...collect(ReportKey::cases())
                    ->map(fn (ReportKey $report): string => $report->permission())
                    ->all(),
                ...collect(ReportWidgetKey::cases())
                    ->filter(fn (ReportWidgetKey $widget): bool => $widget->hasDedicatedPermission())
                    ->map(fn (ReportWidgetKey $widget): string => $widget->permission())
                    ->all(),
            ])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
