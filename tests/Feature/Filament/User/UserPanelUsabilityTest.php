<?php

declare(strict_types=1);

use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Resources\Students\Pages\ListStudents;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Tables\Enums\RecordActionsPosition;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('closes configuration overlays after apply', function (): void {
    $enrollmentsTable = livewire(MyEnrollments::class)
        ->loadTable()
        ->instance()
        ->getTable();
    $columnManagerModalApplyAction = $enrollmentsTable
        ->getColumnManagerTriggerAction()
        ->getExtraModalFooterActions()['applyTableColumnManager'];
    $filtersModalApplyAction = $enrollmentsTable
        ->getFiltersTriggerAction()
        ->getExtraModalFooterActions()['applyFilters'];

    expect($enrollmentsTable->getColumnManagerApplyAction()->getAlpineClickHandler())
        ->toBe('applyTableColumnManager().then(() => close())')
        ->and($columnManagerModalApplyAction->getAlpineClickHandler())
        ->toBe('applyTableColumnManager().then(() => close())')
        ->and($enrollmentsTable->getFiltersApplyAction()->getAlpineClickHandler())
        ->toBe('$wire.applyTableFilters().then(() => close())')
        ->and($filtersModalApplyAction->getAlpineClickHandler())
        ->toBe('$wire.applyTableFilters().then(() => close())');
});

it('keeps the existing user panel record action layout', function (): void {
    $studentsTable = livewire(ListStudents::class)
        ->loadTable()
        ->instance()
        ->getTable();

    expect($studentsTable->getRecordActionsPosition())
        ->not->toBe(RecordActionsPosition::BeforeCells)
        ->and($studentsTable->getRecordActions()[0])
        ->not->toBeInstanceOf(ActionGroup::class);
});

it('provides the floating scrollbar to both panels', function (): void {
    $script = file_get_contents(resource_path('views/filament/shared/table-scrollbars.blade.php'));
    $theme = file_get_contents(resource_path('css/filament/global-theme.css'));

    expect($script)
        ->toContain("'.fi-panel-admin .fi-ta-content-ctn'")
        ->toContain("'.fi-panel-user .fi-ta-content-ctn'")
        ->toContain("rail.className = 'eac-table-scrollbar'")
        ->and($theme)
        ->toContain('.eac-table-scrollbar')
        ->toContain('.eac-table-scrollbar-thumb')
        ->toContain('position: fixed');
});
