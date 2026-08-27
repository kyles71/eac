<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Filament\Clusters\Settings\Pages\AcademicTermDefaults;
use App\Filament\Clusters\Settings\Resources\AcademicTerms\AcademicTermResource;
use App\Filament\Clusters\Settings\Resources\AcademicTerms\Pages\ListAcademicTerms;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('places term management in settings and restricts it to owners', function (): void {
    expect(AcademicTermResource::getCluster())->toBe(SettingsCluster::class)
        ->and(AcademicTermResource::getNavigationSort())->toBe(AdminNavigation::SettingsAcademicTerms);

    $owner = User::factory()->isOwner()->create();
    $this->actingAs($owner);

    expect(AcademicTermResource::canAccess())->toBeTrue()
        ->and(AcademicTermDefaults::canAccess())->toBeTrue();

    $superAdmin = User::factory()->isSuperAdmin()->create();
    $this->actingAs($superAdmin);

    expect(AcademicTermResource::canAccess())->toBeTrue()
        ->and(AcademicTermDefaults::canAccess())->toBeTrue();

    $teacher = User::factory()->isTeacher()->create();
    $this->actingAs($teacher);

    expect(AcademicTermResource::canAccess())->toBeFalse()
        ->and(AcademicTermDefaults::canAccess())->toBeFalse();
});

it('creates terms using the recurring default dates', function (): void {
    livewire(ListAcademicTerms::class)
        ->callAction(CreateAction::class, data: [
            'semester' => CourseSemester::Fall->value,
            'year' => 2030,
            'uses_default_dates' => true,
        ])
        ->assertHasNoActionErrors();

    $term = AcademicTerm::query()
        ->where('semester', CourseSemester::Fall)
        ->where('year', 2030)
        ->firstOrFail();

    expect($term->starts_on->toDateString())->toBe('2030-09-01')
        ->and($term->ends_on->toDateString())->toBe('2030-12-31')
        ->and($term->uses_default_dates)->toBeTrue();
});
