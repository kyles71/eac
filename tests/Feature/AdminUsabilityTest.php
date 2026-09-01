<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Widgets\Reports\EnrollmentOverview;
use App\Http\Middleware\PersistTablePreferences;
use App\Models\Course;
use App\Models\Event;
use App\Models\User;
use App\Services\PermissionCatalogSynchronizerService;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Kyle\FilamentThemeBuilder\Models\Theme;
use Kyle\FilamentThemeBuilder\Pages\ThemeBuilder;
use Kyle\FilamentThemeBuilder\Support\ThemeAuthorization;
use Kyle\FilamentThemeBuilder\Support\ThemeCssRenderer;
use Kyle\FilamentThemeBuilder\ThemeBuilderPlugin;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware as LivewirePersistentMiddleware;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('keeps admin breadcrumbs and uses the shared theme builder plugin', function (): void {
    $panel = Filament::getCurrentPanel();

    expect($panel)
        ->not->toBeNull()
        ->and($panel->hasBreadcrumbs())->toBeTrue()
        ->and($panel->getPlugin('filament-theme-builder'))->toBeInstanceOf(ThemeBuilderPlugin::class)
        ->and(app(ThemeCssRenderer::class))->toBeInstanceOf(ThemeCssRenderer::class);
});

it('keeps the theme builder under its existing permission', function (): void {
    $permissions = app(PermissionCatalogSynchronizerService::class)->desiredPermissions();

    expect($permissions)
        ->toContain('Manage:ThemeBuilder')
        ->not->toContain('View:AdminThemeBuilder');
});

it('saves and renders theme builder body content colors', function (): void {
    app(ThemeAuthorization::class)->use(fn (): bool => true, 'admin');

    $theme = Theme::query()->create([
        'name' => 'Admin Brand',
        'settings' => [],
    ]);

    livewire(ThemeBuilder::class)
        ->assertSchemaComponentExists('colors.body_content')
        ->call('setPreviewColorScheme', 'dark')
        ->assertSchemaComponentExists('dark_colors.body_content');

    $page = app(ThemeBuilder::class);
    $page->mount();
    $page->data['colors']['body_content'] = '#123456';
    $page->data['dark_colors']['body_content'] = '#abcdef';
    $page->saveTheme(shouldNotify: false);

    $settings = $theme->refresh()->settings;
    $css = app(ThemeCssRenderer::class)->render($settings);

    expect($settings['colors']['body_content'])->toBe('#123456')
        ->and($settings['dark_colors']['body_content'])->toBe('#abcdef')
        ->and($css)->toContain('--fi-theme-builder-body-content: #123456')
        ->toContain('--fi-theme-builder-body-content: #abcdef')
        ->toContain('.fi-header-heading')
        ->toContain('.fi-header-subheading')
        ->toContain('.fi-breadcrumbs ol li')
        ->and($page->getExtraBodyAttributes())->toBe(['class' => 'fi-theme-builder-page'])
        ->and($page->shouldCollapseSidebarByDefault())->toBeTrue();
});

it('groups admin table record actions at the left and closes configuration modals after apply', function (): void {
    $table = livewire(ListUsers::class)
        ->loadTable()
        ->instance()
        ->getTable();
    $columnManagerModalApplyAction = $table
        ->getColumnManagerTriggerAction()
        ->getExtraModalFooterActions()['applyTableColumnManager'];
    $filtersModalApplyAction = $table
        ->getFiltersTriggerAction()
        ->getExtraModalFooterActions()['applyFilters'];

    expect($table->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeCells)
        ->and($table->getRecordActions())->toHaveCount(1)
        ->and($table->getRecordActions()[0])->toBeInstanceOf(ActionGroup::class)
        ->and($table->getColumnManagerApplyAction()->getAlpineClickHandler())
        ->toBe('applyTableColumnManager().then(() => close())')
        ->and($columnManagerModalApplyAction->getAlpineClickHandler())
        ->toBe('applyTableColumnManager().then(() => close())')
        ->and($table->getFiltersApplyAction()->getAlpineClickHandler())
        ->toBe('$wire.applyTableFilters().then(() => close())')
        ->and($filtersModalApplyAction->getAlpineClickHandler())
        ->toBe('$wire.applyTableFilters().then(() => close())');
});

it('wraps every nonempty admin record action list in an action group', function (): void {
    $files = [
        ...File::allFiles(app_path('Filament/Admin')),
        ...File::allFiles(app_path('Filament/Clusters/Settings')),
    ];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();
        preg_match_all(
            '/->recordActions\(\[\s*([A-Za-z\\\\]+)::make/',
            $contents,
            $matches,
        );

        foreach ($matches[1] as $firstActionClass) {
            expect($firstActionClass)
                ->toBe('ActionGroup', "Record actions in {$file->getPathname()} must be grouped.");
        }
    }
});

it('defaults courses to active courses taught by the current user', function (): void {
    Carbon::setTestNow('2026-07-29 12:00:00');

    /** @var User $teacher */
    $teacher = auth()->user();
    $myActiveCourse = Course::factory()->create();
    $otherActiveCourse = Course::factory()->create();
    $myConcludedCourse = Course::factory()->create();

    Event::factory()->for($myActiveCourse)->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Event::factory()->for($otherActiveCourse)->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Event::factory()->for($myConcludedCourse)->create([
        'start_time' => now()->subDay(),
        'end_time' => now()->subDay()->addHour(),
    ]);

    $teacher->teachingCourses()->sync([
        $myActiveCourse->id,
        $myConcludedCourse->id,
    ]);

    livewire(ListCourses::class)
        ->loadTable()
        ->assertSet('activeTab', 'my_active')
        ->assertCanSeeTableRecords([$myActiveCourse])
        ->assertCanNotSeeTableRecords([$otherActiveCourse, $myConcludedCourse])
        ->set('activeTab', 'active')
        ->assertCanSeeTableRecords([$myActiveCourse, $otherActiveCourse])
        ->assertCanNotSeeTableRecords([$myConcludedCourse])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$myActiveCourse, $otherActiveCourse, $myConcludedCourse]);
});

it('persists table column preferences per user without changing their update timestamp', function (): void {
    $ownKey = str_repeat('a', 32).'_columns';
    $resetKey = str_repeat('d', 32).'_has_reordered_columns';
    $user = User::factory()->create([
        'table_preferences' => [
            $ownKey => [['name' => 'email', 'isToggled' => false]],
            $resetKey => true,
        ],
    ]);
    $updatedAt = $user->updated_at;
    $otherUserKey = str_repeat('b', 32).'_columns';
    $filterKey = str_repeat('c', 32).'_filters';

    session()->put('tables', [
        $otherUserKey => [['name' => 'private', 'isToggled' => true]],
        $filterKey => ['status' => 'active'],
    ]);

    $request = Request::create('/admin/users');
    $request->setLaravelSession(session()->driver());
    $request->setUserResolver(fn (): User => $user);

    app(PersistTablePreferences::class)->handle(
        $request,
        function (Request $request) use ($filterKey, $otherUserKey, $ownKey, $resetKey): Response {
            expect($request->session()->get("tables.{$ownKey}"))
                ->toBe([['name' => 'email', 'isToggled' => false]])
                ->and($request->session()->get("tables.{$resetKey}"))->toBeTrue()
                ->and($request->session()->has("tables.{$otherUserKey}"))->toBeFalse()
                ->and($request->session()->get("tables.{$filterKey}"))->toBe(['status' => 'active']);

            $request->session()->put("tables.{$ownKey}", [
                ['name' => 'email', 'isToggled' => true],
            ]);
            $request->session()->forget("tables.{$resetKey}");

            return new Response();
        },
    );

    $user->refresh();

    expect($user->table_preferences[$ownKey])
        ->toBe([['name' => 'email', 'isToggled' => true]])
        ->and($user->table_preferences)->not->toHaveKey($resetKey)
        ->and($user->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('wraps real web and admin requests without using Livewire preflight middleware', function (): void {
    $webMiddleware = app(Router::class)->getMiddlewareGroups()['web'];
    $adminMiddleware = Filament::getPanel('admin')->getMiddleware();
    $userMiddleware = Filament::getPanel('user')->getMiddleware();
    $livewirePersistentMiddleware = app(LivewirePersistentMiddleware::class)->getPersistentMiddleware();

    expect($webMiddleware)
        ->toContain(PersistTablePreferences::class)
        ->and($adminMiddleware)
        ->toContain(PersistTablePreferences::class)
        ->and($userMiddleware)
        ->toContain(PersistTablePreferences::class)
        ->and($livewirePersistentMiddleware)
        ->not->toContain(PersistTablePreferences::class);
});

it('provides a floating synchronized scrollbar and sticky left action cells for wide panel tables', function (): void {
    $script = file_get_contents(resource_path('views/filament/shared/table-scrollbars.blade.php'));
    $theme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($script)
        ->toContain("'.fi-panel-admin .fi-ta-content-ctn'")
        ->toContain("'.fi-panel-user .fi-ta-content-ctn'")
        ->toContain('table.scrollWidth > table.clientWidth')
        ->toContain("rail.setAttribute('role', 'scrollbar')")
        ->toContain('activeTable.scrollLeft = dragStartScrollLeft')
        ->toContain('thumb.style.transform')
        ->and($theme)
        ->toContain('.eac-table-scrollbar')
        ->toContain('.eac-table-scrollbar-thumb')
        ->toContain('position: fixed')
        ->toContain('.fi-panel-admin .fi-ta-table>tbody>tr>.fi-ta-cell:has(> .fi-ta-actions):first-child')
        ->toContain('position: sticky')
        ->toContain('inset-inline-start: 0');
});

it('does not let the theme builder style widget layout wrappers or uncontained sections as cards', function (): void {
    $themeCss = app(ThemeCssRenderer::class)->render([]);
    $statsSection = (new EnrollmentOverview)->getSectionContentComponent();

    expect($statsSection->isContained())->toBeFalse()
        ->and($themeCss)
        ->not->toContain('.fi-wi-widget')
        ->not->toContain("\n.fi-section,\n")
        ->toContain('.fi-section:not(.fi-section-not-contained)')
        ->toContain('background: var(--fi-theme-builder-card)')
        ->toContain('box-shadow: var(--fi-theme-builder-shadow)');
});

it('defaults the calendar to list month on mobile and month grid on larger screens', function (): void {
    $view = file_get_contents(resource_path('views/filament/shared/widgets/calendar-widget.blade.php'));

    expect($view)
        ->toContain("window.matchMedia('(max-width: 639px)').matches ? 'listMonth' : 'dayGridMonth'");
});
