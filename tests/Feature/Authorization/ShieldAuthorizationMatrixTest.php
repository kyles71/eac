<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Calendars\CalendarResource;
use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Filament\Admin\Resources\CourseHolds\CourseHoldResource;
use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Filament\Admin\Resources\CreditGrants\CreditGrantResource;
use App\Filament\Admin\Resources\DashboardMessages\DashboardMessageResource;
use App\Filament\Admin\Resources\DashboardQuickLinks\DashboardQuickLinkResource;
use App\Filament\Admin\Resources\DiscountCodes\DiscountCodeResource;
use App\Filament\Admin\Resources\Enrollments\EnrollmentResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\FormUsers\FormUserResource;
use App\Filament\Admin\Resources\Gear\GearResource;
use App\Filament\Admin\Resources\GiftCards\GiftCardResource;
use App\Filament\Admin\Resources\GiftCardTypes\GiftCardTypeResource;
use App\Filament\Admin\Resources\LegalDocuments\LegalDocumentResource;
use App\Filament\Admin\Resources\ManagedBanners\ManagedBannerResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Filament\Admin\Resources\PaymentPlanTemplates\PaymentPlanTemplateResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Students\StudentResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Clusters\Settings\Resources\Holidays\HolidayResource;
use App\Models\Role;
use App\Services\PermissionCatalogSynchronizerService;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('uses the exact strict authorization resource matrix', function (): void {
    $sixAbilities = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'];
    $fiveAbilities = ['viewAny', 'create', 'update', 'delete', 'deleteAny'];

    $expected = [
        CalendarResource::class => $fiveAbilities,
        CompetitionSeasonResource::class => $sixAbilities,
        CompetitionTeamResource::class => $sixAbilities,
        GearResource::class => $fiveAbilities,
        CourseHoldResource::class => ['viewAny', 'view', 'create', 'update'],
        CourseResource::class => $sixAbilities,
        CreditGrantResource::class => ['viewAny', 'view', 'create', 'revoke'],
        DashboardMessageResource::class => $fiveAbilities,
        DashboardQuickLinkResource::class => $fiveAbilities,
        DiscountCodeResource::class => ['viewAny', 'create'],
        EnrollmentResource::class => $fiveAbilities,
        EventResource::class => ['viewAny', 'view', 'create', 'update', 'deleteAny', 'cancel'],
        FormResource::class => ['viewAny', 'view', 'create', 'update', 'deleteAny'],
        FormUserResource::class => ['viewAny', 'view', 'create', 'update', 'deleteAny'],
        GiftCardResource::class => ['viewAny', 'create', 'deleteAny', 'redeem'],
        GiftCardTypeResource::class => ['viewAny', 'create', 'delete', 'deleteAny'],
        HolidayResource::class => $fiveAbilities,
        LegalDocumentResource::class => ['viewAny', 'publish'],
        ManagedBannerResource::class => $fiveAbilities,
        OrderResource::class => ['viewAny', 'view', 'refund'],
        PaymentPlanResource::class => ['viewAny', 'view', 'adjustDueDates'],
        PaymentPlanTemplateResource::class => ['viewAny', 'create', 'update'],
        ProductResource::class => $sixAbilities,
        RoleResource::class => $sixAbilities,
        StudentResource::class => ['viewAny', 'view', 'create', 'update', 'deleteAny'],
        UserResource::class => $sixAbilities,
    ];

    expect(Filament::getPanel('admin')->isAuthorizationStrict())->toBeTrue()
        ->and(config('filament-shield.policies.merge'))->toBeFalse()
        ->and(config('filament-shield.policies.methods'))->toBe([])
        ->and(config('filament-shield.resources.manage'))->toBe($expected);

    foreach ($expected as $resource => $abilities) {
        $policy = Gate::getPolicyFor($resource::getModel());

        expect($policy)->not->toBeNull();

        foreach ($abilities as $ability) {
            expect(method_exists($policy, $ability))->toBeTrue("{$resource} is missing {$ability}().");
        }
    }
});

it('keeps pages and widgets outside the permission catalog', function (): void {
    expect(config('filament-shield.shield_resource.tabs.pages'))->toBeFalse()
        ->and(config('filament-shield.shield_resource.tabs.widgets'))->toBeFalse()
        ->and(FilamentShield::getPages())->toBe([])
        ->and(FilamentShield::getWidgets())->toBe([]);
});

it('keeps the database and super administrator synchronized to the catalog', function (): void {
    $desired = app(PermissionCatalogSynchronizerService::class)->desiredPermissions();
    $databasePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->orderBy('name')
        ->pluck('name')
        ->all();
    $superAdminPermissions = Role::findByName(Role::SUPER_ADMIN)
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($databasePermissions)->toBe($desired)
        ->and($superAdminPermissions)->toBe($desired)
        ->and($desired)->toContain(
            'Manage:DashboardAppearance',
            'Manage:MailManager',
            'Manage:ThemeBuilder',
            'Manage:UserAccess',
            'Publish:LegalDocument',
            'AdjustDueDates:PaymentPlan',
            'View:AppUpdatesPage',
        );

    foreach ($desired as $permission) {
        expect($permission)->not->toMatch('/^(Restore|RestoreAny|ForceDelete|ForceDeleteAny|Replicate|Reorder):/');
    }
});

it('supports a non-mutating permission synchronization dry run', function (): void {
    Permission::findOrCreate('Obsolete:Permission');

    expect(Artisan::call('permissions:sync', ['--dry-run' => true]))->toBe(0)
        ->and(Permission::query()->where('name', 'Obsolete:Permission')->exists())->toBeTrue();

    expect(Artisan::call('permissions:sync'))->toBe(0)
        ->and(Permission::query()->where('name', 'Obsolete:Permission')->exists())->toBeFalse();
});
