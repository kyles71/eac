<?php

declare(strict_types=1);

use App\Enums\DashboardAudience;
use App\Enums\ManagedBannerRenderLocation;
use App\Enums\ManagedBannerTone;
use App\Filament\Admin\Resources\ManagedBanners\ManagedBannerResource;
use App\Filament\Admin\Resources\ManagedBanners\Pages\CreateManagedBanner;
use App\Filament\Admin\Resources\ManagedBanners\Pages\EditManagedBanner;
use App\Filament\Admin\Resources\ManagedBanners\Pages\ListManagedBanners;
use App\Filament\User\Pages\Cart;
use App\Filament\User\Pages\CheckoutSuccess;
use App\Filament\User\Pages\Dashboard;
use App\Filament\User\Widgets\ManagedBanners;
use App\Http\Middleware\ManagedBanners as ManagedBannersMiddleware;
use App\Http\Middleware\UserBanners as UserBannersMiddleware;
use App\Models\ManagedBanner;
use App\Models\User;
use App\Services\ManagedBannerDestinationService;
use App\Services\ManagedBannerScopeService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Guava\IconPicker\Forms\Components\IconPicker;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

it('filters visible managed banners by schedule audience scope location and dismissal', function (): void {
    $owner = User::factory()->isOwner()->create();
    $this->actingAs($owner);

    $visible = ManagedBanner::factory()
        ->dismissible()
        ->forScope(Dashboard::class)
        ->create([
            'title' => 'Visible on dashboard',
            'audiences' => [DashboardAudience::Owner->value],
        ]);

    ManagedBanner::factory()->forScope(Dashboard::class)->create([
        'title' => 'Future banner',
        'audiences' => [DashboardAudience::Owner->value],
        'published_at' => now()->addMinute(),
    ]);
    ManagedBanner::factory()->forScope(Dashboard::class)->create([
        'title' => 'Wrong audience',
        'audiences' => [DashboardAudience::CompTeam->value],
    ]);
    ManagedBanner::factory()->forScope(Cart::class)->create([
        'title' => 'Wrong page',
        'audiences' => [DashboardAudience::Owner->value],
    ]);
    ManagedBanner::factory()->forRenderLocation(ManagedBannerRenderLocation::PageEnd)->create([
        'title' => 'Wrong location',
        'audiences' => [DashboardAudience::Owner->value],
    ]);

    $titles = ManagedBanner::query()
        ->forRenderLocation(ManagedBannerRenderLocation::ContentStart)
        ->matchingScopes([Dashboard::class])
        ->visibleTo($owner)
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Visible on dashboard']);

    $visible->dismissFor($owner);

    $titles = ManagedBanner::query()
        ->forRenderLocation(ManagedBannerRenderLocation::ContentStart)
        ->matchingScopes([Dashboard::class])
        ->visibleTo($owner)
        ->pluck('title')
        ->all();

    expect($titles)->toBe([]);
});

it('keeps banner hook middleware persistent for Livewire updates', function (): void {
    $persistentMiddleware = Livewire::getPersistentMiddleware();

    expect($persistentMiddleware)
        ->toContain(ManagedBannersMiddleware::class)
        ->toContain(UserBannersMiddleware::class);
});

it('manages banners from the admin panel', function (): void {
    Filament::setCurrentPanel('admin');

    $scopeService = app(ManagedBannerScopeService::class);
    $destinationService = app(ManagedBannerDestinationService::class);

    expect(CreateManagedBanner::$formActionsAreSticky)->toBeTrue()
        ->and(EditManagedBanner::$formActionsAreSticky)->toBeTrue();

    livewire(CreateManagedBanner::class)
        ->assertSchemaComponentExists(
            'icon',
            checkComponentUsing: fn (IconPicker $iconPicker): bool => $iconPicker->getSets() === ['heroicons'],
        )
        ->assertSchemaComponentExists(
            'render_location',
            checkComponentUsing: fn (Select $select): bool => $select->getOptions() === [
                ManagedBannerRenderLocation::ContentStart->value => 'Content start',
                ManagedBannerRenderLocation::ContentEnd->value => 'Content end',
                ManagedBannerRenderLocation::PageStart->value => 'Page start',
                ManagedBannerRenderLocation::PageEnd->value => 'Page end',
                ManagedBannerRenderLocation::PageHeaderWidgetsBefore->value => 'Before header widgets',
                ManagedBannerRenderLocation::PageHeaderWidgetsAfter->value => 'After header widgets',
                ManagedBannerRenderLocation::PageFooterWidgetsBefore->value => 'Before footer widgets',
                ManagedBannerRenderLocation::PageFooterWidgetsAfter->value => 'After footer widgets',
                ManagedBannerRenderLocation::SidebarNavStart->value => 'Sidebar nav start',
                ManagedBannerRenderLocation::SidebarNavEnd->value => 'Sidebar nav end',
            ],
        )
        ->assertSchemaComponentExists(
            'target_scopes',
            checkComponentUsing: fn (Select $select): bool => $select->isMultiple()
                && $select->isSearchable()
                && collect($select->getOptions())
                    ->contains(fn (array $options): bool => array_key_exists($scopeService->keyFor('user', Dashboard::class), $options))
                && collect($select->getOptions())
                    ->contains(fn (array $options): bool => array_key_exists($scopeService->keyFor('user', CheckoutSuccess::class), $options))
                && collect($select->getOptions())
                    ->contains(fn (array $options): bool => array_key_exists($scopeService->keyFor('admin', ListManagedBanners::class), $options)),
        )
        ->assertSchemaComponentExists(
            'cta_destination',
            checkComponentUsing: fn (Select $select): bool => $select->isSearchable()
                && collect($select->getOptions())
                    ->contains(fn (array $options): bool => array_key_exists($destinationService->keyFor('user', Cart::class), $options))
                && collect($select->getOptions())
                    ->contains(fn (array $options): bool => array_key_exists($destinationService->keyFor('admin', ManagedBannerResource::class), $options)),
        )
        ->assertSchemaComponentExists(
            'audiences',
            checkComponentUsing: fn (Select $select): bool => $select->isMultiple()
                && $select->getOptions() === [
                    DashboardAudience::Eac->value => 'EAC Audience',
                    DashboardAudience::Semester->value => 'Semester Audience',
                    DashboardAudience::CompTeam->value => 'Comp Team Audience',
                    DashboardAudience::Teacher->value => 'Teacher Audience',
                    DashboardAudience::Owner->value => 'Owner Audience',
                ],
        )
        ->assertDontSee('Example Title')
        ->assertDontSee('This is an example banner to show where and how the banner will display as currently configured')
        ->assertDontSee('Selected hook:')
        ->assertSeeHtml('data-managed-banner-preview-canvas')
        ->assertSeeHtml('managed-banner-preview-pane')
        ->assertSeeHtml('data-managed-banner-preview-slot-active="panels::content.start"')
        ->fillForm([
            'title' => 'Summer schedule update',
            'message' => 'Classes are moving to the north studio tonight.',
            'tone' => ManagedBannerTone::Warning->value,
            'icon' => 'heroicon-o-megaphone',
            'is_active' => true,
            'is_dismissible' => true,
            'published_at' => now()->subMinute(),
            'expires_at' => now()->addWeek(),
            'audiences' => [DashboardAudience::Owner->value, DashboardAudience::Teacher->value],
            'target_scopes' => [
                $scopeService->keyFor('user', Dashboard::class),
                $scopeService->keyFor('user', CheckoutSuccess::class),
            ],
            'render_location' => ManagedBannerRenderLocation::ContentStart->value,
            'cta_label' => 'View cart',
            'cta_destination' => $destinationService->keyFor('user', Cart::class),
            'cta_new_tab' => false,
        ])
        ->assertSee('Content start')
        ->assertSeeHtml('data-managed-banner-preview-slot-active="panels::content.start"')
        ->assertSeeHtml('data-managed-banner-preview-title="Summer schedule update"')
        ->assertSeeHtml('data-managed-banner-preview-message="Classes are moving to the north studio tonight."')
        ->assertSee('View cart')
        ->assertDontSee('Destination: '.Cart::getNavigationLabel())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    assertDatabaseHas(ManagedBanner::class, [
        'title' => 'Summer schedule update',
        'tone' => ManagedBannerTone::Warning->value,
        'render_location' => ManagedBannerRenderLocation::ContentStart->value,
    ]);

    $banner = ManagedBanner::query()->firstOrFail();

    expect($banner->audiences)->toBe([DashboardAudience::Owner->value, DashboardAudience::Teacher->value])
        ->and($banner->target_scopes)->toBe([
            $scopeService->keyFor('user', Dashboard::class),
            $scopeService->keyFor('user', CheckoutSuccess::class),
        ])
        ->and($banner->icon)->toBe('heroicon-o-megaphone')
        ->and($banner->resolvedCtaUrl())->toContain('/dancefam/cart')
        ->and($scopeService->labelFor($scopeService->keyFor('user', CheckoutSuccess::class)))->toBeString();

    livewire(ListManagedBanners::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$banner])
        ->assertTableFilterExists('tone')
        ->assertTableFilterExists('render_location')
        ->assertTableFilterExists('audience')
        ->assertTableFilterExists('status');
});

it('previews edited banner appearance placement and call to action settings', function (): void {
    Filament::setCurrentPanel('admin');

    $destinationService = app(ManagedBannerDestinationService::class);

    $banner = ManagedBanner::factory()->create([
        'title' => 'Existing banner',
        'message' => 'Saved copy.',
        'tone' => ManagedBannerTone::Danger,
        'render_location' => ManagedBannerRenderLocation::PageEnd,
        'cta_label' => 'Open dashboard',
        'cta_destination' => $destinationService->keyFor('user', Dashboard::class),
    ]);

    livewire(EditManagedBanner::class, ['record' => $banner->id])
        ->assertDontSee('Example Title')
        ->assertDontSee('This is an example banner to show where and how the banner will display as currently configured')
        ->assertSeeHtml('data-managed-banner-preview-title="Existing banner"')
        ->assertSeeHtml('data-managed-banner-preview-message="Saved copy."')
        ->assertDontSee('Selected hook:')
        ->assertSee('Page end')
        ->assertSeeHtml('data-managed-banner-preview-slot-active="panels::page.end"')
        ->assertSee('Open dashboard')
        ->assertDontSee('Destination: '.Dashboard::getNavigationLabel())
        ->fillForm([
            'title' => 'Edited banner',
            'message' => 'Fresh preview copy.',
            'tone' => ManagedBannerTone::Success->value,
            'icon' => 'heroicon-o-megaphone',
            'render_location' => ManagedBannerRenderLocation::SidebarNavEnd->value,
            'cta_label' => 'View cart',
            'cta_destination' => $destinationService->keyFor('user', Cart::class),
        ])
        ->assertSee('Sidebar nav end')
        ->assertSeeHtml('data-managed-banner-preview-slot-active="panels::sidebar.nav.end"')
        ->assertDontSeeHtml('data-managed-banner-preview-slot-active="panels::page.end"')
        ->assertSeeHtml('data-managed-banner-preview-title="Edited banner"')
        ->assertSeeHtml('data-managed-banner-preview-message="Fresh preview copy."')
        ->assertSee('View cart')
        ->assertDontSee('Destination: '.Cart::getNavigationLabel());
});

it('renders scoped managed banners and persists dismissals per user', function (): void {
    $owner = User::factory()->isOwner()->create();
    $this->actingAs($owner);

    $global = ManagedBanner::factory()
        ->dismissible()
        ->create([
            'title' => 'Global notice',
            'message' => 'Visible everywhere.',
            'audiences' => [DashboardAudience::Owner->value],
            'target_scopes' => [],
            'cta_label' => 'Open cart',
            'cta_destination' => app(ManagedBannerDestinationService::class)->keyFor('user', Cart::class),
        ]);
    ManagedBanner::factory()->forScope(Dashboard::class)->create([
        'title' => 'Dashboard notice',
        'audiences' => [DashboardAudience::Owner->value],
    ]);
    ManagedBanner::factory()->forScope(Cart::class)->create([
        'title' => 'Cart notice',
        'audiences' => [DashboardAudience::Owner->value],
    ]);

    $this->get('/dancefam')
        ->assertOk()
        ->assertSeeText('Global notice')
        ->assertSeeText('Dashboard notice')
        ->assertDontSeeText('Cart notice')
        ->assertSeeText('Open cart');

    livewire(ManagedBanners::class, [
        'renderLocation' => ManagedBannerRenderLocation::ContentStart->value,
        'scopes' => [Dashboard::class],
    ])
        ->assertSee('Global notice')
        ->call('dismiss', $global->id)
        ->assertDontSee('Global notice');

    expect($global->fresh()->isDismissedBy($owner))->toBeTrue();

    $otherOwner = User::factory()->isOwner()->create();
    $this->actingAs($otherOwner);

    livewire(ManagedBanners::class, [
        'renderLocation' => ManagedBannerRenderLocation::ContentStart->value,
        'scopes' => [Dashboard::class],
    ])
        ->assertSee('Global notice');
});

it('collapses empty managed banner hook roots', function (): void {
    $owner = User::factory()->isOwner()->create();
    $this->actingAs($owner);

    livewire(ManagedBanners::class, [
        'renderLocation' => ManagedBannerRenderLocation::ContentStart->value,
        'scopes' => [Dashboard::class],
    ])
        ->assertSeeHtml('data-managed-banners-empty="true"')
        ->assertSeeHtml('class="hidden"')
        ->assertSeeHtml('aria-hidden="true"');

    ManagedBanner::factory()
        ->forScope(Dashboard::class)
        ->create([
            'title' => 'Visible hook root',
            'audiences' => [DashboardAudience::Owner->value],
        ]);

    livewire(ManagedBanners::class, [
        'renderLocation' => ManagedBannerRenderLocation::ContentStart->value,
        'scopes' => [Dashboard::class],
    ])
        ->assertSee('Visible hook root')
        ->assertSeeHtml('data-managed-banners-empty="false"')
        ->assertDontSeeHtml('data-managed-banners-empty="true"');
});

it('renders managed banners on admin panel pages when scoped there', function (): void {
    $scopeService = app(ManagedBannerScopeService::class);

    ManagedBanner::factory()->create([
        'title' => 'Admin banner notice',
        'message' => 'Visible in admin.',
        'audiences' => [DashboardAudience::Owner->value],
        'target_scopes' => [$scopeService->keyFor('admin', ListManagedBanners::class)],
    ]);
    ManagedBanner::factory()->create([
        'title' => 'User-only banner notice',
        'audiences' => [DashboardAudience::Owner->value],
        'target_scopes' => [$scopeService->keyFor('user', Dashboard::class)],
    ]);

    $this->get(ManagedBannerResource::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSeeText('Admin banner notice')
        ->assertDontSeeText('User-only banner notice');
});
