<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Calendars\CalendarResource;
use App\Filament\Admin\Resources\DashboardMessages\DashboardMessageResource;
use App\Filament\Admin\Resources\DashboardQuickLinks\DashboardQuickLinkResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Calendar;
use App\Models\User;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch;

use function Pest\Livewire\livewire;

it('can global search', function (): void {
    Calendar::factory()->create(['name' => 'Test Calendar']);

    livewire(GlobalSearch::class)
        ->set('search', 'test')
        ->assertOk();
});

it('keeps settings resources out of global search when they have no record-level view ability', function (): void {
    expect(CalendarResource::canGloballySearch())->toBeFalse()
        ->and(DashboardMessageResource::canGloballySearch())->toBeFalse()
        ->and(DashboardQuickLinkResource::canGloballySearch())->toBeFalse();
});

it('can global search for users', function (string $attribute): void {
    $record = User::factory()->create();

    UserResource::getGlobalSearchResults($record->{$attribute})
        ->each(function (GlobalSearchResult $result) use ($record): void {
            expect($result->title)->toBe($record->fullName);
        });
})->with(UserResource::getGloballySearchableAttributes());
