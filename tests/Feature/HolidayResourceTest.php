<?php

declare(strict_types=1);

use App\Enums\HolidayEventScope;
use App\Filament\Admin\Resources\Calendars\CalendarResource;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Filament\Admin\Resources\LegalDocuments\LegalDocumentResource;
use App\Filament\Clusters\Settings\Resources\Holidays\HolidayResource;
use App\Filament\Clusters\Settings\Resources\Holidays\Pages\ManageHolidays;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Event;
use App\Models\Holiday;
use App\Support\Filament\AdminNavigation;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('requires confirmation before deleting existing event conflicts', function (): void {
    $event = Event::factory()->create([
        'course_id' => null,
        'start_time' => '2027-11-28 15:00:00',
        'end_time' => '2027-11-28 16:00:00',
    ]);

    livewire(ManageHolidays::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Thanksgiving',
            'starts_on' => '2027-11-27',
            'ends_on' => '2027-11-30',
            'scope' => HolidayEventScope::AllEvents->value,
        ])
        ->assertHasActionErrors(['confirm_conflict_deletion']);

    expect(Event::query()->whereKey($event->id)->exists())->toBeTrue()
        ->and(Holiday::query()->where('name', 'Thanksgiving')->exists())->toBeFalse();
});

it('creates a holiday and deletes confirmed conflicts', function (): void {
    $event = Event::factory()->create([
        'course_id' => null,
        'start_time' => '2027-11-28 15:00:00',
        'end_time' => '2027-11-28 16:00:00',
    ]);
    $expectedHoliday = new Holiday();
    $expectedHoliday->deletedConflictingEventsCount = 1;

    livewire(ManageHolidays::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Thanksgiving',
            'starts_on' => '2027-11-27',
            'ends_on' => '2027-11-30',
            'scope' => HolidayEventScope::AllEvents->value,
            'confirm_conflict_deletion' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(HolidayResource::saveNotification($expectedHoliday, 'created'));

    assertDatabaseHas(Holiday::class, [
        'name' => 'Thanksgiving',
        'starts_on' => '2027-11-27 00:00:00',
        'ends_on' => '2027-11-30 00:00:00',
        'scope' => HolidayEventScope::AllEvents->value,
    ]);

    expect(Event::query()->whereKey($event->id)->exists())->toBeFalse();
});

it('places the selected definition resources in the settings cluster', function (): void {
    expect(CalendarResource::getCluster())->toBe(SettingsCluster::class)
        ->and(FormResource::getCluster())->toBe(SettingsCluster::class)
        ->and(HolidayResource::getCluster())->toBe(SettingsCluster::class)
        ->and(LegalDocumentResource::getCluster())->toBe(SettingsCluster::class)
        ->and(CalendarResource::getNavigationSort())->toBe(AdminNavigation::SettingsCalendars)
        ->and(FormResource::getNavigationSort())->toBe(AdminNavigation::SettingsForms)
        ->and(LegalDocumentResource::getNavigationSort())->toBe(AdminNavigation::SettingsLegalDocuments)
        ->and(HolidayResource::getNavigationSort())->toBe(AdminNavigation::SettingsHolidays);
});
