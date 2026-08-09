<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\CourseHolds\Pages\ListCourseHolds;
use App\Filament\Admin\Resources\CourseHolds\Pages\ViewCourseHold;
use App\Models\Course;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\Event;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

it('grants course hold permissions to super administrators through its migration', function (): void {
    $permissions = [
        'Create:CourseHold',
        'Update:CourseHold',
        'View:CourseHold',
        'ViewAny:CourseHold',
    ];
    $migration = require database_path('migrations/2026_08_03_032825_grant_course_hold_permissions_to_super_admin.php');

    $migration->down();

    expect(Permission::query()->whereIn('name', $permissions)->exists())->toBeFalse();

    $migration->up();

    $assignedPermissions = Role::findByName(Role::SUPER_ADMIN, 'web')
        ->permissions()
        ->whereIn('name', $permissions)
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($assignedPermissions)->toBe($permissions);
});

it('creates a class hold from the administrator form', function (): void {
    $family = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Held Acro', 'capacity' => 2]);
    Product::factory()->forCourse($course)->create(['price' => 15_000]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);

    $component = livewire(ListCourseHolds::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'user_id' => $family->id,
            'expires_at' => now()->addDays(2),
            'notes' => 'Created by support',
        ]);

    $lineStateKey = array_key_first($component->instance()->mountedActions[0]['data']['lines']);

    $component
        ->fillForm([
            'lines' => [
                $lineStateKey => ['course_id' => (string) $course->id, 'quantity' => 2],
            ],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $hold = CourseHold::query()->where('user_id', $family->id)->sole();

    expect($hold->notes)->toBe('Created by support')
        ->and($hold->seats)->toHaveCount(2)
        ->and($hold->seats->pluck('locked_unit_price')->unique()->all())->toBe([15_000]);
});

it('shows a useful notification when a class hold exceeds available seats', function (): void {
    $family = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Ballet 5', 'capacity' => 2]);
    Product::factory()->forCourse($course)->create(['price' => 15_000]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);

    $component = livewire(ListCourseHolds::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'user_id' => $family->id,
            'expires_at' => now()->addDays(2),
        ]);

    $lineStateKey = array_key_first($component->instance()->mountedActions[0]['data']['lines']);

    $component
        ->fillForm([
            'lines' => [
                $lineStateKey => ['course_id' => (string) $course->id, 'quantity' => 3],
            ],
        ])
        ->callMountedAction()
        ->assertActionHalted();

    $notification = collect(
        session()->get('filament.claimed_notifications')
            ?? session()->get('filament.notifications'),
    )
        ->firstWhere('title', 'Class hold could not be created');

    expect($notification)->not->toBeNull()
        ->and($notification['body'])->toBe('Not enough unreserved seats remain in "Ballet 5".')
        ->and($notification['status'])->toBe('danger');

    $component->assertNotified('Class hold could not be created');

    expect(CourseHold::query()->where('user_id', $family->id)->exists())->toBeFalse();
});

it('opens class hold create and edit forms in slideovers', function (): void {
    $hold = CourseHold::factory()->create(['user_id' => User::factory()]);

    livewire(ListCourseHolds::class)
        ->assertActionExists(
            CreateAction::class,
            fn (Action $action): bool => $action->isModalSlideOver(),
        );

    livewire(ViewCourseHold::class, ['record' => $hold->id])
        ->assertActionExists(
            EditAction::class,
            fn (Action $action): bool => $action->isModalSlideOver(),
        );
});

it('updates a class hold from the edit slideover', function (): void {
    $hold = CourseHold::factory()->create([
        'user_id' => User::factory(),
        'expires_at' => now()->addDay(),
        'notes' => null,
    ]);

    livewire(ViewCourseHold::class, ['record' => $hold->id])
        ->mountAction(EditAction::class)
        ->fillForm([
            'expires_at' => now()->addDays(3),
            'notes' => 'Extended by support',
            'additional_lines' => [],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($hold->refresh()->notes)->toBe('Extended by support')
        ->and($hold->expires_at->isSameMinute(now()->addDays(3)))->toBeTrue();
});

it('lists class holds for administrators', function (): void {
    $hold = CourseHold::factory()->create(['user_id' => User::factory()]);
    $course = Course::factory()->create();
    Product::factory()->forCourse($course)->create();
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
        'course_id' => $course->id,
    ]);

    livewire(ListCourseHolds::class)
        ->loadTable()
        ->assertOk()
        ->assertCanSeeTableRecords([$hold]);
});

it('renders hold details for administrators', function (): void {
    $family = User::factory()->create(['first_name' => 'Jordan', 'last_name' => 'Family']);
    $course = Course::factory()->create(['name' => 'Reserved Tap']);
    Product::factory()->forCourse($course)->create();
    $hold = CourseHold::factory()->create([
        'user_id' => $family->id,
        'notes' => 'Special accommodation',
    ]);
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
        'course_id' => $course->id,
    ]);

    livewire(ViewCourseHold::class, ['record' => $hold->id])
        ->assertOk()
        ->assertSee('Reserved Tap')
        ->assertSee('Special accommodation');
});
