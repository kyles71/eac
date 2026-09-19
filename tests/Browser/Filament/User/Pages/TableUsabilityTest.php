<?php

declare(strict_types=1);

use App\Filament\User\Resources\Students\Pages\ListStudents;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
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
        ->check('.fi-ta-col-manager input[id$="-created_at"]');

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

it('keeps live table search text in entry order across server updates', function (): void {
    Student::factory()->create([
        'user_id' => auth()->id(),
        'first_name' => 'Avery',
    ]);

    $search = '.fi-ta-header-toolbar .fi-ta-search-field input';
    $page = visit('/dancefam/students', [
        'viewport' => [
            'width' => 390,
            'height' => 844,
        ],
    ])
        ->click($search)
        ->typeSlowly($search, '1', 20)
        ->wait(0.7);

    expect($page->script(<<<'JS'
        (() => {
            const input = document.querySelector('.fi-ta-header-toolbar .fi-ta-search-field input')

            return {
                active: document.activeElement === input,
                initialized: window.eacTableSearchInitialized ?? false,
                selectionStart: input.selectionStart,
                value: input.value,
            }
        })()
        JS))->toBe([
        'active' => true,
        'initialized' => true,
        'selectionStart' => 1,
        'value' => '1',
    ]);

    $page
        ->typeSlowly($search, '2', 20)
        ->wait(0.7)
        ->typeSlowly($search, '3', 20)
        ->wait(0.7)
        ->assertSee('Search: 123')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('{$search}').value"))->toBe('123');

    $page
        ->keys($search, ['Home', 'ArrowRight'])
        ->typeSlowly($search, '0', 20)
        ->wait(0.7)
        ->assertSee('Search: 1023');

    expect($page->script("document.querySelector('{$search}').value"))->toBe('1023');

    $page
        ->keys($search, ['End', 'Backspace'])
        ->wait(0.7)
        ->assertSee('Search: 102')
        ->type($search, 'Avery')
        ->keys($search, 'Enter')
        ->wait(0.2)
        ->assertSee('Avery')
        ->type($search, '')
        ->wait(0.7)
        ->assertDontSee('Search:')
        ->assertNoJavaScriptErrors();
});

it('stacks both panel tables below the small breakpoint', function (): void {
    Student::factory()->create([
        'user_id' => auth()->id(),
        'first_name' => 'Avery',
        'last_name' => 'A very long student name that should wrap on a phone',
    ]);
    User::factory()->create([
        'first_name' => 'Mobile',
        'last_name' => 'Table User',
    ]);

    foreach (['/dancefam/students' => 'Avery', '/admin/users' => 'Mobile'] as $url => $visibleRecord) {
        foreach ([360, 390, 639] as $width) {
            $mobile = visit($url, [
                'viewport' => [
                    'width' => $width,
                    'height' => 844,
                ],
            ])
                ->assertSee($visibleRecord)
                ->assertNoJavaScriptErrors();

            $mobileMetrics = $mobile->script(<<<'JS'
                (() => {
                    const table = document.querySelector('.fi-ta-table')
                    const row = table?.querySelector('tbody > tr:has(.fi-ta-cell-content)')
                    const dataCell = row?.querySelector('.fi-ta-cell:not(.fi-ta-selection-cell):not(:has(> .fi-ta-actions))')
                    const actionCell = row?.querySelector('.fi-ta-cell:has(> .fi-ta-actions)')
                    const selectionCell = row?.querySelector('.fi-ta-selection-cell')
                    const search = document.querySelector('.fi-ta-header-toolbar .fi-ta-search-field')
                    const rail = document.querySelector('.eac-table-scrollbar')
                    const actionBounds = actionCell?.getBoundingClientRect()
                    const dataBounds = dataCell?.getBoundingClientRect()
                    const rowStyles = row ? getComputedStyle(row) : null
                    const selectionBounds = selectionCell?.getBoundingClientRect()
                    const dataCellStyles = dataCell ? getComputedStyle(dataCell) : null
                    const actionCellStyles = actionCell ? getComputedStyle(actionCell) : null
                    const selectionCellStyles = selectionCell ? getComputedStyle(selectionCell) : null

                    return {
                        actionBackground: actionCellStyles?.backgroundColor ?? null,
                        actionBottom: actionBounds?.bottom ?? null,
                        actionLeft: actionBounds?.left ?? null,
                        actionPosition: actionCellStyles?.position ?? null,
                        actionTop: actionBounds?.top ?? null,
                        cellDisplay: dataCell ? getComputedStyle(dataCell).display : null,
                        dataBackground: dataCellStyles?.backgroundColor ?? null,
                        dataTop: dataBounds?.top ?? null,
                        dividerColor: rowStyles?.borderBottomColor ?? null,
                        dividerWidth: rowStyles?.borderBottomWidth ?? null,
                        railHidden: rail?.hidden ?? null,
                        rowDisplay: row ? getComputedStyle(row).display : null,
                        searchWidth: search?.getBoundingClientRect().width ?? 0,
                        selectionBackground: selectionCellStyles?.backgroundColor ?? null,
                        selectionBottom: selectionBounds?.bottom ?? null,
                        selectionLeft: selectionBounds?.left ?? null,
                        selectionPosition: selectionCellStyles?.position ?? null,
                        selectionTop: selectionBounds?.top ?? null,
                        stacked: table?.classList.contains('fi-ta-table-stacked-on-mobile') ?? false,
                        tableDisplay: table ? getComputedStyle(table).display : null,
                    }
                })()
                JS);

            expect($mobileMetrics['stacked'])->toBeTrue()
                ->and($mobileMetrics['tableDisplay'])->toBe('block')
                ->and($mobileMetrics['rowDisplay'])->toBe('grid')
                ->and($mobileMetrics['cellDisplay'])->toBe('grid')
                ->and($mobileMetrics['dividerWidth'])->toBe('2px')
                ->and($mobileMetrics['dividerColor'])->toBe('rgb(156, 163, 175)')
                ->and($mobileMetrics['searchWidth'])->toBeGreaterThan(250)
                ->and($mobileMetrics['dataTop'])->toBeGreaterThanOrEqual($mobileMetrics['actionBottom'])
                ->and($mobileMetrics['railHidden'])->toBeTrue();

            if ($url === '/admin/users') {
                expect($mobileMetrics['selectionPosition'])->toBe('static')
                    ->and($mobileMetrics['actionPosition'])->toBe('static')
                    ->and($mobileMetrics['selectionBackground'])->toBe($mobileMetrics['dataBackground'])
                    ->and($mobileMetrics['actionBackground'])->toBe($mobileMetrics['dataBackground'])
                    ->and($mobileMetrics['selectionLeft'])->toBeLessThan($mobileMetrics['actionLeft'])
                    ->and(abs($mobileMetrics['selectionTop'] - $mobileMetrics['actionTop']))->toBeLessThan(1)
                    ->and($mobileMetrics['actionTop'])->toBeLessThan($mobileMetrics['dataTop'])
                    ->and($mobileMetrics['dataTop'])->toBeGreaterThanOrEqual($mobileMetrics['selectionBottom']);
            }
        }

        $desktop = visit($url, [
            'viewport' => [
                'width' => 640,
                'height' => 844,
            ],
        ])
            ->assertSee($visibleRecord)
            ->assertNoJavaScriptErrors();

        $desktopMetrics = $desktop->script(<<<'JS'
            (() => {
                const table = document.querySelector('.fi-ta-table')
                const row = table?.querySelector('tbody > tr:has(.fi-ta-cell-content)')
                const actionCell = row?.querySelector('.fi-ta-cell:has(> .fi-ta-actions)')

                return {
                    actionPosition: actionCell ? getComputedStyle(actionCell).position : null,
                    rowDisplay: row ? getComputedStyle(row).display : null,
                    tableDisplay: table ? getComputedStyle(table).display : null,
                }
            })()
            JS);

        expect($desktopMetrics['tableDisplay'])->toBe('table')
            ->and($desktopMetrics['rowDisplay'])->toBe('table-row');

        if ($url === '/admin/users') {
            expect($desktopMetrics['actionPosition'])->toBe('sticky');
        }
    }
});

it('shows the compact attention summary and review slide-over at mobile and desktop widths', function (): void {
    $course = Course::factory()->create(['name' => 'Mobile Ballet']);
    Event::factory()->for($course)->create([
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'student_id' => null,
        'user_id' => auth()->id(),
    ]);

    foreach ([390, 1280] as $width) {
        $page = visit('/dancefam', [
            'viewport' => [
                'width' => $width,
                'height' => 844,
            ],
        ])
            ->assertSee('1 item needs attention')
            ->assertSee('1 class seat')
            ->click('Review')
            ->assertSee('Items Needing Attention')
            ->assertSee('Class Assignments')
            ->assertSee('Mobile Ballet')
            ->assertSee('Assign student')
            ->assertNoJavaScriptErrors();

        $summaryDirection = $page->script(<<<'JS'
            getComputedStyle(document.querySelector('[data-user-attention-summary]')).flexDirection
            JS);

        expect($summaryDirection)->toBe($width < 640 ? 'column' : 'row');
    }
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
