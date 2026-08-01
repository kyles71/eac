<?php

declare(strict_types=1);

use App\Filament\User\Resources\Students\Pages\ListStudents;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;

beforeEach(function (): void {
    $this->withVite();
});

it('closes table configuration overlays after applying changes', function (): void {
    Enrollment::factory()->create([
        'user_id' => auth()->id(),
    ]);

    $page = visit('/dancefam/my-enrollments?tab=all')
        ->click('Columns')
        ->assertSee('Apply columns')
        ->click('Apply columns')
        ->assertDontSee('Apply columns')
        ->assertNoJavaScriptErrors();

    $page = visit('/dancefam/my-enrollments?tab=all')
        ->click('.fi-ta-filters-dropdown .fi-dropdown-trigger button')
        ->assertSee('Apply filters')
        ->click('Apply filters')
        ->assertDontSee('Apply filters')
        ->assertNoJavaScriptErrors();
});

it('persists user table columns and their order across browser sessions', function (): void {
    Student::factory()->create([
        'user_id' => auth()->id(),
    ]);

    $page = visit('/dancefam/students')
        ->click('Columns')
        ->check('.fi-ta-col-manager input[id="column-created_at"]');

    $page->script(<<<'JS'
        (() => {
            const manager = document.querySelector('.fi-ta-col-manager')
            const state = Alpine.$data(manager)
            const createdAtIndex = state.deferredColumns.findIndex(
                (column) => column.name === 'created_at',
            )
            const [createdAt] = state.deferredColumns.splice(createdAtIndex, 1)

            state.deferredColumns.splice(1, 0, createdAt)
            state.deferredColumns = [...state.deferredColumns]
            state.hasReordered = true
        })()
        JS);

    $page
        ->click('Apply columns')
        ->assertDontSee('Apply columns')
        ->assertNoJavaScriptErrors();

    $preferenceKey = md5(ListStudents::class);
    $user = User::query()->findOrFail(auth()->id());
    $storedColumns = $user->table_preferences["{$preferenceKey}_columns"];

    expect($storedColumns[1]['name'])->toBe('created_at')
        ->and($storedColumns[1]['isToggled'])->toBeTrue()
        ->and($user->table_preferences["{$preferenceKey}_has_reordered_columns"])->toBeTrue();

    $freshSessionPage = visit('/dancefam/students')
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
        'createdAtIndex' => 1,
        'createdAtIsToggled' => true,
    ]);
});

it('shows a floating scrollbar while a wide user table extends below the viewport', function (): void {
    Enrollment::factory()->count(15)->create([
        'user_id' => auth()->id(),
    ]);

    $page = visit('/dancefam/my-enrollments?tab=all', [
        'viewport' => [
            'width' => 1250,
            'height' => 500,
        ],
    ])
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        const table = document.querySelector('.fi-panel-user .fi-ta-table')

        table.style.minWidth = '1400px'
        window.dispatchEvent(new Event('resize'))
        JS);
    $page->wait(0.1);

    $metrics = $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('.fi-panel-user .fi-ta-content-ctn')
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
            const table = document.querySelector('.fi-panel-user .fi-ta-content-ctn')
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
