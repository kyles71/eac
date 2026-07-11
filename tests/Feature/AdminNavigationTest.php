<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Calendars\CalendarResource;
use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Filament\Admin\Resources\CreditGrants\CreditGrantResource;
use App\Filament\Admin\Resources\DiscountCodes\DiscountCodeResource;
use App\Filament\Admin\Resources\Enrollments\EnrollmentResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\FormUsers\FormUserResource;
use App\Filament\Admin\Resources\GiftCards\GiftCardResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Students\StudentResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Shared\Pages\Calendar;
use App\Support\Filament\AdminNavigation;
use Filament\Facades\Filament;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('groups high traffic admin resources by workflow', function (): void {
    expect(UserResource::getNavigationGroup())->toBe(AdminNavigation::PeopleAndAccess)
        ->and(StudentResource::getNavigationGroup())->toBe(AdminNavigation::PeopleAndAccess)
        ->and(FormUserResource::getNavigationGroup())->toBe(AdminNavigation::PeopleAndAccess)
        ->and(RoleResource::getNavigationGroup())->toBe(AdminNavigation::PeopleAndAccess)
        ->and(CourseResource::getNavigationGroup())->toBe(AdminNavigation::ClassesAndSchedule)
        ->and(EventResource::getNavigationGroup())->toBe(AdminNavigation::ClassesAndSchedule)
        ->and(EnrollmentResource::getNavigationGroup())->toBe(AdminNavigation::ClassesAndSchedule)
        ->and(ProductResource::getNavigationGroup())->toBe(AdminNavigation::Storefront)
        ->and(DiscountCodeResource::getNavigationGroup())->toBe(AdminNavigation::Storefront)
        ->and(OrderResource::getNavigationGroup())->toBe(AdminNavigation::SalesAndBilling)
        ->and(PaymentPlanResource::getNavigationGroup())->toBe(AdminNavigation::SalesAndBilling)
        ->and(GiftCardResource::getNavigationGroup())->toBe(AdminNavigation::SalesAndBilling)
        ->and(CreditGrantResource::getNavigationGroup())->toBe(AdminNavigation::SalesAndBilling)
        ->and(CompetitionSeasonResource::getNavigationGroup())->toBe(AdminNavigation::Competition)
        ->and(CompetitionTeamResource::getNavigationGroup())->toBe(AdminNavigation::Competition);
});

it('keeps workflow resource ordering explicit', function (): void {
    expect(UserResource::getNavigationSort())->toBe(AdminNavigation::PeopleUsers)
        ->and(StudentResource::getNavigationSort())->toBe(AdminNavigation::PeopleStudents)
        ->and(FormUserResource::getNavigationSort())->toBe(AdminNavigation::PeopleFormAssignments)
        ->and(RoleResource::getNavigationSort())->toBe(AdminNavigation::PeopleRoles)
        ->and(CourseResource::getNavigationSort())->toBe(AdminNavigation::ScheduleCourses)
        ->and(EnrollmentResource::getNavigationSort())->toBe(AdminNavigation::ScheduleEnrollments)
        ->and(ProductResource::getNavigationSort())->toBe(AdminNavigation::StoreProducts)
        ->and(OrderResource::getNavigationSort())->toBe(AdminNavigation::BillingOrders)
        ->and(PaymentPlanResource::getNavigationSort())->toBe(AdminNavigation::BillingPaymentPlans);
});

it('keeps settings resources inside the settings cluster', function (): void {
    expect(SettingsCluster::getNavigationGroup())->toBe(AdminNavigation::Settings)
        ->and(CalendarResource::getCluster())->toBe(SettingsCluster::class)
        ->and(CalendarResource::getNavigationSort())->toBe(AdminNavigation::SettingsCalendars);
});

it('groups the shared calendar only in the admin panel', function (): void {
    expect(Calendar::getNavigationGroup())->toBe(AdminNavigation::ClassesAndSchedule)
        ->and(Calendar::getNavigationSort())->toBe(AdminNavigation::ScheduleCalendar);

    Filament::setCurrentPanel('user');

    expect(Calendar::getNavigationGroup())->toBeNull()
        ->and(Calendar::getNavigationSort())->toBeNull();
});

it('uses admin-friendly labels for renamed resources', function (): void {
    expect(FormUserResource::getNavigationLabel())->toBe('Form Assignments')
        ->and(FormUserResource::getModelLabel())->toBe('Form Assignment')
        ->and(FormUserResource::getPluralModelLabel())->toBe('Form Assignments');
});
