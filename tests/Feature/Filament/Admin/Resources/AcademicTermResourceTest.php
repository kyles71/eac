<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Filament\Clusters\Settings\Pages\AcademicTermDefaults;
use App\Filament\Clusters\Settings\Resources\AcademicTerms\AcademicTermResource;
use App\Filament\Clusters\Settings\Resources\AcademicTerms\Pages\ListAcademicTerms;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Settings\AcademicTermSettings;
use App\Support\Filament\AdminNavigation;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
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

it('edits recurring term settings when immutable term fields are not submitted', function (): void {
    $term = AcademicTerm::factory()->create([
        'semester' => CourseSemester::Fall,
        'year' => 2030,
        'starts_on' => '2030-09-02',
        'ends_on' => '2030-12-30',
        'uses_default_dates' => true,
    ]);

    livewire(ListAcademicTerms::class)
        ->callAction(TestAction::make(EditAction::class)->table($term), data: [
            'uses_default_dates' => true,
            'target_enrollments' => 400,
            'stretch_goal_enrollments' => 500,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $term->refresh();

    expect($term->starts_on->toDateString())->toBe('2030-09-01')
        ->and($term->ends_on->toDateString())->toBe('2030-12-31')
        ->and($term->target_enrollments)->toBe(400)
        ->and($term->stretch_goal_enrollments)->toBe(500);
});

it('shows recurring date overlap errors on the visible toggle', function (): void {
    CarbonImmutable::setTestNow('2026-08-30 12:00:00');
    AcademicTerm::query()->delete();

    $settings = app(AcademicTermSettings::class);
    $settings->fall_starts_on = '08-01';
    $settings->save();

    AcademicTerm::factory()->forSemester(CourseSemester::Summer, 2026)->create([
        'ends_on' => '2026-08-19',
        'uses_default_dates' => false,
    ]);
    $fall = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2026)->create([
        'starts_on' => '2026-08-20',
        'uses_default_dates' => false,
    ]);

    livewire(ListAcademicTerms::class)
        ->callAction(TestAction::make(EditAction::class)->table($fall), data: [
            'uses_default_dates' => true,
        ])
        ->assertHasActionErrors([
            'uses_default_dates' => 'The recurring default dates overlap Summer 2026 (Jun 1, 2026–Aug 19, 2026). Turn off recurring defaults or adjust the overlapping term first.',
        ]);

    $fall->refresh();

    expect($fall->starts_on->toDateString())->toBe('2026-08-20')
        ->and($fall->uses_default_dates)->toBeFalse();
});

it('preserves historical default-backed dates when editing other term details', function (): void {
    CarbonImmutable::setTestNow('2026-08-30 12:00:00');
    AcademicTerm::query()->delete();

    $fall = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2025)->create([
        'starts_on' => '2025-09-01',
        'ends_on' => '2025-12-31',
        'uses_default_dates' => true,
    ]);

    $settings = app(AcademicTermSettings::class);
    $settings->fall_starts_on = '08-01';
    $settings->save();

    livewire(ListAcademicTerms::class)
        ->callAction(TestAction::make(EditAction::class)->table($fall), data: [
            'uses_default_dates' => true,
            'target_enrollments' => 300,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $fall->refresh();

    expect($fall->starts_on->toDateString())->toBe('2025-09-01')
        ->and($fall->ends_on->toDateString())->toBe('2025-12-31')
        ->and($fall->target_enrollments)->toBe(300);
});
