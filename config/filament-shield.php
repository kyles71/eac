<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Updates;
use App\Filament\Admin\Resources\Calendars\CalendarResource;
use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Filament\Admin\Resources\Costumes\CostumeResource;
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
use App\Filament\Admin\Resources\GiftCards\GiftCardResource;
use App\Filament\Admin\Resources\GiftCardTypes\GiftCardTypeResource;
use App\Filament\Admin\Resources\LegalDocuments\LegalDocumentResource;
use App\Filament\Admin\Resources\ManagedBanners\ManagedBannerResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Filament\Admin\Resources\PaymentPlanTemplates\PaymentPlanTemplateResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\SentEmails\SentEmailResource;
use App\Filament\Admin\Resources\Students\StudentResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Clusters\Settings\Pages\ManageDashboardAppearance;
use App\Filament\Clusters\Settings\Resources\Holidays\HolidayResource;
use App\Filament\Shared\Pages\Calendar as CalendarPage;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Filament\Shared\Widgets\MessagesFromEac;
use App\Filament\Shared\Widgets\QuickLinks;

return [

    /*
    |--------------------------------------------------------------------------
    | Shield Resource
    |--------------------------------------------------------------------------
    |
    | Here you may configure the built-in role management resource. You can
    | customize the URL, choose whether to show model paths, group it under
    | a cluster, and decide which permission tabs to display.
    |
    */

    'shield_resource' => [
        'slug' => 'shield/roles',
        'show_model_path' => true,
        'cluster' => null,
        'tabs' => [
            'pages' => false,
            'widgets' => false,
            'resources' => true,
            'custom_permissions' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When your application supports teams, Shield will automatically detect
    | and configure the tenant model during setup. This enables tenant-scoped
    | roles and permissions throughout your application.
    |
    */

    'tenant_model' => null,

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | This value contains the class name of your user model. This model will
    | be used for role assignments and must implement the HasRoles trait
    | provided by the Spatie\Permission package.
    |
    */

    'auth_provider_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | Here you may define a super admin that has unrestricted access to your
    | application. You can choose to implement this via Laravel's gate system
    | or as a traditional role with all permissions explicitly assigned.
    |
    */

    'super_admin' => [
        'enabled' => true,
        'name' => 'super_admin',
        'define_via_gate' => false,
        'intercept_gate' => 'before',
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel User
    |--------------------------------------------------------------------------
    |
    | When enabled, Shield will create a basic panel user role that can be
    | assigned to users who should have access to your Filament panels but
    | don't need any specific permissions beyond basic authentication.
    |
    */

    'panel_user' => [
        'enabled' => false,
        'name' => 'panel_user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Builder
    |--------------------------------------------------------------------------
    |
    | You can customize how permission keys are generated to match your
    | preferred naming convention and organizational standards. Shield uses
    | these settings when creating permission names from your resources.
    |
    | Supported formats: snake, kebab, pascal, camel, upper_snake, lower_snake
    |
    */

    'permissions' => [
        'separator' => ':',
        'case' => 'pascal',
        'generate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Shield can automatically generate Laravel policies for your resources.
    | When merge is enabled, the methods below will be combined with any
    | resource-specific methods you define in the resources section.
    |
    */

    'policies' => [
        'path' => app_path('Policies'),
        'merge' => false,
        'generate' => true,
        'methods' => [],
        'single_parameter_methods' => [
            'viewAny',
            'create',
            'deleteAny',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Shield supports multiple languages out of the box. When enabled, you
    | can provide translated labels for permissions to create a more
    | localized experience for your international users.
    |
    */

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Here you can fine-tune permissions for specific Filament resources.
    | Use the 'manage' array to override the default policy methods for
    | individual resources, giving you granular control over permissions.
    |
    */

    'resources' => [
        'subject' => 'model',
        'manage' => [
            CalendarResource::class => [
                'viewAny', 'create', 'update', 'delete', 'deleteAny',
            ],
            CompetitionSeasonResource::class => [
                'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            ],
            CompetitionTeamResource::class => [
                'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            ],
            CostumeResource::class => [
                'viewAny', 'create', 'update', 'delete', 'deleteAny',
            ],
            CourseHoldResource::class => [
                'viewAny', 'view', 'create', 'update',
            ],
            CourseResource::class => [
                'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            ],
            CreditGrantResource::class => [
                'viewAny', 'view', 'create', 'revoke',
            ],
            DashboardMessageResource::class => [
                'viewAny', 'create', 'update', 'delete', 'deleteAny',
            ],
            DashboardQuickLinkResource::class => [
                'viewAny', 'create', 'update', 'delete', 'deleteAny',
            ],
            DiscountCodeResource::class => [
                'viewAny', 'create',
            ],
            EnrollmentResource::class => [
                'viewAny', 'create', 'update', 'delete', 'deleteAny',
            ],
            EventResource::class => [
                'viewAny', 'view', 'create', 'update', 'deleteAny', 'cancel',
            ],
            FormResource::class => [
                'viewAny', 'view', 'create', 'update', 'deleteAny',
            ],
            FormUserResource::class => [
                'viewAny', 'view', 'create', 'update', 'deleteAny',
            ],
            GiftCardResource::class => [
                'viewAny', 'create', 'deleteAny', 'redeem',
            ],
            GiftCardTypeResource::class => [
                'viewAny', 'create', 'delete', 'deleteAny',
            ],
            HolidayResource::class => [
                'viewAny', 'create', 'update', 'delete', 'deleteAny',
            ],
            LegalDocumentResource::class => [
                'viewAny', 'publish',
            ],
            ManagedBannerResource::class => [
                'viewAny', 'create', 'update', 'delete', 'deleteAny',
            ],
            OrderResource::class => [
                'viewAny', 'view',
            ],
            PaymentPlanResource::class => [
                'viewAny', 'view', 'adjustDueDates',
            ],
            PaymentPlanTemplateResource::class => [
                'viewAny', 'create', 'update',
            ],
            ProductResource::class => [
                'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            ],
            RoleResource::class => [
                'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            ],
            StudentResource::class => [
                'viewAny', 'view', 'create', 'update', 'deleteAny',
            ],
            UserResource::class => [
                'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
            ],
        ],
        'exclude' => [
            SentEmailResource::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Most Filament pages only require view permissions. Pages listed in the
    | exclude array will be skipped during permission generation and won't
    | appear in your role management interface.
    |
    */

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            CalendarPage::class,
            Dashboard::class,
            ManageDashboardAppearance::class,
            Updates::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Widgets
    |--------------------------------------------------------------------------
    |
    | Like pages, widgets typically only need view permissions. Add widgets
    | to the exclude array if you don't want them to appear in your role
    | management interface.
    |
    */

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            CalendarWidget::class,
            MessagesFromEac::class,
            QuickLinks::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Permissions
    |--------------------------------------------------------------------------
    |
    | Sometimes you need permissions that don't map to resources, pages, or
    | widgets. Define any custom permissions here and they'll be available
    | when editing roles in your application.
    |
    */

    'custom_permissions' => [
        'Manage:DashboardAppearance' => 'Manage Dashboard Appearance',
        'Manage:MailManager' => 'Manage Mail Manager',
        'Manage:ThemeBuilder' => 'Manage Theme Builder',
        'Manage:UserAccess' => 'Manage User Access',
        'Revoke:CreditGrant' => 'Revoke Store Credit',
        'View:AppUpdatesPage' => 'View App Updates Page',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Discovery
    |--------------------------------------------------------------------------
    |
    | By default, Shield only looks for entities in your default Filament
    | panel. Enable these options if you're using multiple panels and want
    | Shield to discover entities across all of them.
    |
    */

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Policy
    |--------------------------------------------------------------------------
    |
    | Shield can automatically register a policy for role management itself.
    | This lets you control who can manage roles using Laravel's built-in
    | authorization system. Requires a RolePolicy class in your app.
    |
    */

    'register_role_policy' => true,

];
