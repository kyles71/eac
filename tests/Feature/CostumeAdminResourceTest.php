<?php

declare(strict_types=1);

use App\Enums\CourseProgramType;
use App\Filament\Admin\Resources\Costumes\CostumeResource;
use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Filament\Admin\Resources\Costumes\Pages\ViewCostume;
use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Products\Pages\PurchaseStatus;
use App\Models\AcademicTerm;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('creates and lists costumes with required course data', function (): void {
    $course = Course::factory()->competition()->create();

    livewire(ListCostumes::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'New Lyrical Costume',
            'course_academic_term_id' => null,
            'course_id' => $course->id,
            'vendor' => 'Costume Vendor',
            'vendor_number' => 'CV-42',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    assertDatabaseHas(Costume::class, [
        'name' => 'New Lyrical Costume',
        'course_id' => $course->id,
        'vendor_number' => 'CV-42',
    ]);

    livewire(ListCostumes::class)
        ->loadTable()
        ->assertCanSeeTableRecords(Costume::query()->get());
});

it('defaults costume courses to the current academic term and can show every term', function (): void {
    Carbon::setTestNow('2026-09-04 12:00:00');

    try {
        $currentTerm = AcademicTerm::query()->current()->firstOrFail();
        $pastTerm = AcademicTerm::query()->whereDate('ends_on', '<', now())->firstOrFail();
        $currentCourse = Course::factory()->for($currentTerm, 'academicTerm')->create(['name' => 'Current Ballet']);
        $pastCourse = Course::factory()->for($pastTerm, 'academicTerm')->create(['name' => 'Past Ballet']);

        livewire(ListCostumes::class)
            ->mountAction(CreateAction::class)
            ->assertSchemaComponentStateSet('course_academic_term_id', $currentTerm->id)
            ->assertSchemaComponentExists(
                'course_id',
                checkComponentUsing: function (Select $select) use ($currentCourse, $pastCourse): bool {
                    $options = $select->getOptions();

                    return array_key_exists($currentCourse->id, $options)
                        && ! array_key_exists($pastCourse->id, $options)
                        && ! $select->canSelectPlaceholder();
                },
            )
            ->fillForm(['course_academic_term_id' => null])
            ->assertSchemaComponentExists(
                'course_id',
                checkComponentUsing: function (Select $select) use ($currentCourse, $pastCourse): bool {
                    $options = $select->getOptions();

                    return array_key_exists($currentCourse->id, $options)
                        && array_key_exists($pastCourse->id, $options);
                },
            );
    } finally {
        Carbon::setTestNow();
    }
});

it('creates and edits the single product listing from a costume', function (): void {
    $household = User::factory()->create();
    $course = Course::factory()->create(['program_type' => CourseProgramType::Standard]);
    $student = Student::factory()->for($household)->create();
    Enrollment::factory()->for($course)->for($household)->withStudent($student)->create();
    $costume = Costume::factory()->for($course)->create();

    livewire(ListCostumes::class)
        ->assertActionHasLabel(
            TestAction::make('manageProductListing')->table($costume),
            'Create Product Listing',
        )
        ->callAction(TestAction::make('manageProductListing')->table($costume), data: [
            'name' => 'Fall Costume Listing',
            'price' => '125.00',
            'is_active' => true,
            'assignedStudents' => [$student->id],
            'is_purchase_required' => true,
            'purchase_reminder_on' => '2026-10-01',
            'available_until' => '2026-10-15 23:59:59',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $product = Product::query()->where('productable_type', Costume::class)->sole();

    expect($product->price)->toBe(12500)
        ->and($product->assignedStudents()->pluck('students.id')->all())->toBe([$student->id]);

    livewire(ListCostumes::class)
        ->assertActionHasLabel(
            TestAction::make('manageProductListing')->table($costume),
            'Edit Product Listing',
        )
        ->callAction(TestAction::make('manageProductListing')->table($costume), data: [
            'name' => 'Updated Costume Listing',
            'price' => '130.00',
            'is_active' => true,
            'assignedStudents' => [$student->id],
            'is_purchase_required' => true,
            'purchase_reminder_on' => '2026-10-15',
            'available_until' => '2026-10-31 23:59:59',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Product::query()->forProductable($costume)->count())->toBe(1)
        ->and($product->refresh()->name)->toBe('Updated Costume Listing')
        ->and($product->price)->toBe(13000);
});

it('creates and edits the single product listing from a course', function (): void {
    $course = Course::factory()->create(['name' => 'Intro to Ballet']);

    livewire(ListCourses::class)
        ->set('activeTab', 'all')
        ->callAction(TestAction::make('manageProductListing')->table($course), data: [
            'name' => 'Intro to Ballet Tuition',
            'price' => '80.00',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $product = $course->refresh()->product;

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->name)->toBe('Intro to Ballet Tuition')
        ->and($product->price)->toBe(8000);

    livewire(ListCourses::class)
        ->set('activeTab', 'all')
        ->callAction(TestAction::make('manageProductListing')->table($course), data: [
            'name' => 'Updated Ballet Tuition',
            'price' => '85.00',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(Product::query()->forProductable($course)->count())->toBe(1)
        ->and($product->refresh()->name)->toBe('Updated Ballet Tuition')
        ->and($product->price)->toBe(8500);
});

it('uses a compact single product listing on costume details', function (): void {
    $costume = Costume::factory()->create();
    Product::factory()->forCostume($costume)->create([
        'name' => 'Compact Listing',
        'price' => 9900,
    ]);

    expect(CostumeResource::getRelations())->toBe([]);

    livewire(ViewCostume::class, ['record' => $costume->id])
        ->assertSee('Product Listing')
        ->assertSee('Compact Listing')
        ->assertSee('$99.00')
        ->assertActionVisible('manageProductListing');
});

it('keeps required course selects fixed and removes record metadata from course details', function (): void {
    $course = Course::factory()->create();

    livewire(ListCourses::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentExists(
            'academic_term_id',
            checkComponentUsing: fn (Select $select): bool => ! $select->canSelectPlaceholder(),
        )
        ->assertSchemaComponentExists(
            'program_type',
            checkComponentUsing: fn (Select $select): bool => ! $select->isSearchable()
                && ! $select->canSelectPlaceholder(),
        );

    livewire(ViewCourse::class, ['record' => $course->id])
        ->assertDontSeeText('Record')
        ->assertDontSeeText('Created At')
        ->assertDontSeeText('Updated At');
});

it('renders searchable costume order status', function (): void {
    $household = User::factory()->create(['first_name' => 'Targeted', 'last_name' => 'Household']);
    $course = Course::factory()->create();
    Enrollment::factory()->for($course)->for($household)->create();
    $costume = Costume::factory()->for($course)->create();
    $product = Product::factory()->forCostume($costume)->purchaseRequired()->create();

    livewire(PurchaseStatus::class, ['record' => $product->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$household])
        ->searchTable('Targeted')
        ->assertCanSeeTableRecords([$household]);
});
