<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Calendar::factory()->create();
    $this->withVite();
});

it('can create a new user', function () {
    $user = User::factory()->make();

    visit('/admin/users')
        ->click('New User')
        ->assertSee('Create User')
        ->fill('[id="mountedActionSchema0.first_name"]', $user->first_name)
        ->fill('[id="mountedActionSchema0.last_name"]', $user->last_name)
        ->fill('[id="mountedActionSchema0.email"]', $user->email)
        ->fill('[id="mountedActionSchema0.password"]', 'password')
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->assertSee('Created');

    assertDatabaseHas('users', [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
    ]);
});

it('can edit an existing user', function () {
    $existingUser = User::factory()->create();
    $newData = User::factory()->make();

    visit("/admin/users/{$existingUser->id}")
        ->click('Edit')
        ->assertSee('Save')
        ->fill('[id="mountedActionSchema0.first_name"]', $newData->first_name)
        ->fill('[id="mountedActionSchema0.last_name"]', $newData->last_name)
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->assertSee('Saved');

    assertDatabaseHas('users', [
        'id' => $existingUser->id,
        'first_name' => $newData->first_name,
        'last_name' => $newData->last_name,
    ]);
});

it('renders the direct permission picker without JavaScript errors', function () {
    $user = User::factory()->create();

    visit("/admin/users/{$user->id}")
        ->click('Manage Access')
        ->assertSee('Direct Permissions')
        ->assertSee('Cards')
        ->assertSee('Select all')
        ->assertNoJavaScriptErrors();
});

it('updates teaching courses immediately after changing the teacher role', function (): void {
    $user = User::factory()->create();
    $teacherRole = Role::findByName('teacher');

    $page = visit("/admin/users/{$user->id}?relation=1");

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('.fi-sc-tabs .fi-tabs-item-label')]
            .some((label) => label.textContent.trim() === 'Courses')
        JS))->toBeFalse();

    $page
        ->click('Manage Access')
        ->check(".fi-modal-window input[wire\\:model=\"mountedActions.0.data.roles\"][value=\"{$teacherRole->id}\"]")
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->wait(0.2)
        ->assertNoJavaScriptErrors();

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('.fi-sc-tabs .fi-tabs-item-label')]
            .some((label) => label.textContent.trim() === 'Courses')
        JS))->toBeTrue();
});

it('shows reactive password requirements while creating a user', function (): void {
    $page = visit('/admin/users')
        ->click('New User')
        ->assertSee('Password requirements:')
        ->assertNoJavaScriptErrors();

    expect($page->script(<<<'JS'
        document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-gray-500')
        JS))->toBeTrue()
        ->and($page->script(<<<'JS'
            document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-danger-600')
            JS))->toBeFalse()
        ->and($page->script(<<<'JS'
            getComputedStyle(document.querySelector('[data-password-requirement="maximum-length"]')).display
            JS))->toBe('none')
        ->and($page->script(<<<'JS'
            getComputedStyle(document.querySelector('[data-password-requirement="uncompromised"]')).display
            JS))->toBe('none');

    $page
        ->fill('[id="mountedActionSchema0.password"]', 'short')
        ->keys('[id="mountedActionSchema0.password"]', 'Tab');

    expect($page->script(<<<'JS'
        document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-danger-600')
        JS))->toBeTrue();

    $page->fill('[id="mountedActionSchema0.password"]', 'long-enough');

    expect($page->script(<<<'JS'
        document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-success-600')
        JS))->toBeTrue();

    $page->script(<<<'JS'
        Alpine.$data(document.querySelector('[data-password-requirements]')).password = 'a'.repeat(256)
        JS);
    $page->wait(0.1);

    expect($page->script(<<<'JS'
        getComputedStyle(document.querySelector('[data-password-requirement="maximum-length"]')).display
        JS))->not->toBe('none');
});

it('closes table configuration modals after applying changes', function (): void {
    $page = visit('/admin/users')
        ->click('Columns')
        ->assertSee('Apply columns')
        ->click('Apply columns')
        ->assertDontSee('Apply columns')
        ->assertNoJavaScriptErrors();

    $page = visit('/admin/users')
        ->click('.fi-ta-filters-dropdown .fi-dropdown-trigger button')
        ->assertSee('Apply filters')
        ->click('Apply filters')
        ->assertDontSee('Apply filters')
        ->assertNoJavaScriptErrors();
});

it('persists table columns and their order across browser sessions', function (): void {
    Course::factory()->create();

    $page = visit('/admin/courses?tab=all')
        ->click('Columns')
        ->check('.fi-ta-col-manager input[id$="-created_at"]');

    $page->script(<<<'JS'
        (() => {
            const manager = document.querySelector('.fi-ta-col-manager')
            const state = Alpine.$data(manager)
            const createdAtIndex = state.deferredColumns.findIndex(
                (column) => column.name === 'created_at',
            )
            const [createdAt] = state.deferredColumns.splice(createdAtIndex, 1)

            state.deferredColumns.splice(2, 0, createdAt)
            state.deferredColumns = [...state.deferredColumns]
            state.hasReordered = true
        })()
        JS);

    $page
        ->click('Apply columns')
        ->assertDontSee('Apply columns')
        ->assertNoJavaScriptErrors();

    $preferenceKey = md5(ListCourses::class);
    $user = User::query()->findOrFail(auth()->id());
    $storedColumns = $user->table_preferences["{$preferenceKey}_columns"];

    expect($storedColumns[2]['name'])->toBe('created_at')
        ->and($storedColumns[2]['isToggled'])->toBeTrue()
        ->and($user->table_preferences["{$preferenceKey}_has_reordered_columns"])->toBeTrue();

    $freshSessionPage = visit('/admin/courses?tab=all')
        ->click('Columns')
        ->assertNoJavaScriptErrors();
    $restoredState = $freshSessionPage->script(<<<'JS'
        (() => {
            const state = Alpine.$data(document.querySelector('.fi-ta-col-manager'))
            const createdAt = state.deferredColumns.find(
                (column) => column.name === 'created_at',
            )

            return {
                createdAtIndex: state.deferredColumns.findIndex(
                    (column) => column.name === 'created_at',
                ),
                createdAtIsToggled: createdAt?.isToggled ?? false,
            }
        })()
        JS);

    expect($restoredState)->toBe([
        'createdAtIndex' => 2,
        'createdAtIsToggled' => true,
    ]);
});

it('temporarily collapses the theme builder sidebar and restores its previous state', function (): void {
    $page = visit('/admin/users')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        Alpine.store('sidebar').open()
        Livewire.navigate('/admin/theme-builder')
        JS);
    $page
        ->assertSee('Live Preview')
        ->wait(0.1);

    expect($page->script(<<<'JS'
        ({
            isOpen: Alpine.store('sidebar').isOpen,
            sidebarDisplay: getComputedStyle(document.querySelector('.fi-sidebar')).display,
        })
        JS))->toBe([
        'isOpen' => false,
        'sidebarDisplay' => 'flex',
    ]);

    $page->script(<<<'JS'
        Livewire.navigate('/admin/users')
        JS);
    $page
        ->assertSee('New User')
        ->wait(0.1);

    expect($page->script("Alpine.store('sidebar').isOpen"))->toBeTrue();

    $page->script(<<<'JS'
        Alpine.store('sidebar').close()
        Livewire.navigate('/admin/theme-builder')
        JS);
    $page
        ->assertSee('Live Preview')
        ->wait(0.1);

    expect($page->script("Alpine.store('sidebar').isOpen"))->toBeFalse();

    $page->script(<<<'JS'
        Alpine.store('sidebar').open()
        Livewire.navigate('/admin/users')
        JS);
    $page
        ->assertSee('New User')
        ->wait(0.1);

    expect($page->script("Alpine.store('sidebar').isOpen"))->toBeFalse();
});

it('shows a floating scrollbar while a wide table extends below the viewport', function (): void {
    User::factory()->count(15)->create();

    $page = visit('/admin/users', [
        'viewport' => [
            'width' => 1250,
            'height' => 500,
        ],
    ])
        ->assertNoJavaScriptErrors();

    $page->wait(0.1);
    $metrics = $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('.fi-panel-admin .fi-ta-content-ctn')
            const rail = document.querySelector('.eac-table-scrollbar')
            const thumb = document.querySelector('.eac-table-scrollbar-thumb')

            return {
                tableFound: table !== null,
                tableClientWidth: table?.clientWidth ?? null,
                tableScrollWidth: table?.scrollWidth ?? null,
                tableBottom: table?.getBoundingClientRect().bottom ?? null,
                viewportHeight: window.innerHeight,
                railFound: rail !== null,
                railHidden: rail?.hidden ?? null,
                railWidth: rail?.getBoundingClientRect().width ?? null,
                thumbWidth: thumb?.getBoundingClientRect().width ?? null,
                thumbColor: thumb ? getComputedStyle(thumb).backgroundColor : null,
            }
        })()
        JS);

    expect($metrics['tableFound'])->toBeTrue()
        ->and($metrics['tableScrollWidth'])->toBeGreaterThan($metrics['tableClientWidth'])
        ->and($metrics['tableBottom'])->toBeGreaterThan($metrics['viewportHeight'])
        ->and($metrics['railFound'])->toBeTrue()
        ->and($metrics['railHidden'])->toBeFalse()
        ->and($metrics['railWidth'])->toBeGreaterThan(0)
        ->and($metrics['thumbWidth'])->toBeGreaterThan(0)
        ->and($metrics['thumbColor'])->toBe('rgb(107, 114, 128)');

    $scrolled = $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('.fi-panel-admin .fi-ta-content-ctn')
            const rail = document.querySelector('.eac-table-scrollbar')
            const thumb = document.querySelector('.eac-table-scrollbar-thumb')

            rail.dispatchEvent(new KeyboardEvent('keydown', {
                bubbles: true,
                key: 'End',
            }))

            return {
                tableScrollLeft: table.scrollLeft,
                thumbTransform: thumb.style.transform,
            }
        })()
        JS);

    expect($scrolled['tableScrollLeft'])->toBeGreaterThan(0)
        ->and($scrolled['thumbTransform'])->not->toBe('translateX(0px)');
});
