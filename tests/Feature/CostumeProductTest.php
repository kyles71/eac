<?php

declare(strict_types=1);

use App\Enums\CourseProgramType;
use App\Enums\ProductAvailabilityStatus;
use App\Enums\ProductType;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use App\Services\ProductStudentAssignmentService;
use Illuminate\Validation\ValidationException;

it('maps costumes to their own product type and stores course metadata', function (): void {
    $course = Course::factory()->competition()->create();
    $costume = Costume::factory()->for($course)->create([
        'name' => 'Blue Ballet Costume',
        'vendor' => 'Curtain Call',
        'vendor_number' => 'CC-100',
        'notes' => 'Order tights separately.',
    ]);

    expect($costume->course->is($course))->toBeTrue()
        ->and($costume->course->program_type)->toBe(CourseProgramType::Competition)
        ->and(ProductType::fromProductableType(Costume::class))->toBe(ProductType::Costume)
        ->and(ProductType::Costume->toProductableClass())->toBe(Costume::class);
});

it('only makes a course-wide costume visible to households enrolled in its course', function (): void {
    $course = Course::factory()->create();
    $enrolledHousehold = User::factory()->create();
    $unrelatedHousehold = User::factory()->create();
    Enrollment::factory()->for($course)->for($enrolledHousehold)->create();
    $product = Product::factory()->forCostume(Costume::factory()->for($course)->create())->create();

    expect($product->canBePurchasedBy($enrolledHousehold))->toBeTrue()
        ->and(Product::query()->visibleTo($enrolledHousehold)->whereKey($product)->exists())->toBeTrue()
        ->and($product->canBePurchasedBy($unrelatedHousehold))->toBeFalse()
        ->and($product->availabilityFor($unrelatedHousehold))->toBe(ProductAvailabilityStatus::EligibilityRequired)
        ->and(Product::query()->visibleTo($unrelatedHousehold)->whereKey($product)->exists())->toBeFalse();
});

it('limits student-assigned costumes without allowing generic user assignments to bypass the course', function (): void {
    $course = Course::factory()->create();
    $includedHousehold = User::factory()->create();
    $otherHousehold = User::factory()->create();
    $unrelatedHousehold = User::factory()->create();
    $includedStudent = Student::factory()->for($includedHousehold)->create();
    $otherStudent = Student::factory()->for($otherHousehold)->create();
    Enrollment::factory()->for($course)->for($includedHousehold)->withStudent($includedStudent)->create();
    Enrollment::factory()->for($course)->for($otherHousehold)->withStudent($otherStudent)->create();
    $product = Product::factory()->forCostume(Costume::factory()->for($course)->create())->create();
    app(ProductStudentAssignmentService::class)->sync($product, [$includedStudent->id]);

    expect($product->refresh()->canBePurchasedBy($includedHousehold))->toBeTrue()
        ->and($product->canBePurchasedBy($otherHousehold))->toBeFalse()
        ->and(Product::query()->visibleTo($includedHousehold)->whereKey($product)->exists())->toBeTrue()
        ->and(Product::query()->visibleTo($otherHousehold)->whereKey($product)->exists())->toBeFalse();

    $product->assignedUsers()->attach($unrelatedHousehold);

    expect($product->refresh()->canBePurchasedBy($unrelatedHousehold))->toBeFalse()
        ->and(Product::query()->visibleTo($unrelatedHousehold)->whereKey($product)->exists())->toBeFalse();
});

it('rejects student assignments outside the costume course', function (): void {
    $course = Course::factory()->create();
    $otherCourse = Course::factory()->create();
    $student = Student::factory()->create();
    Enrollment::factory()->for($otherCourse)->for($student->user)->withStudent($student)->create();
    $product = Product::factory()->forCostume(Costume::factory()->for($course)->create())->create();

    expect(fn () => app(ProductStudentAssignmentService::class)->sync($product, [$student->id]))
        ->toThrow(ValidationException::class);
});

it('allows only one product listing per costume', function (): void {
    $costume = Costume::factory()->create();
    Product::factory()->forCostume($costume)->create();

    expect(fn () => Product::factory()->forCostume($costume)->create())
        ->toThrow(ValidationException::class);
});

it('matches costume payment plans by the related course program type', function (): void {
    $standardProduct = Product::factory()->forCostume(
        Costume::factory()->for(Course::factory()->create())->create(),
    )->create(['price' => 10000]);
    $competitionProduct = Product::factory()->forCostume(
        Costume::factory()->for(Course::factory()->competition()->create())->create(),
    )->create(['price' => 10000]);
    $standardTemplate = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Costume,
        'costume_program_types' => [CourseProgramType::Standard->value],
    ]);
    $competitionTemplate = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Costume,
        'costume_program_types' => [CourseProgramType::Competition->value],
    ]);

    expect($standardTemplate->matchesProduct($standardProduct, 10000))->toBeTrue()
        ->and($standardTemplate->matchesProduct($competitionProduct, 10000))->toBeFalse()
        ->and($competitionTemplate->matchesProduct($competitionProduct, 10000))->toBeTrue()
        ->and($competitionTemplate->matchesProduct($standardProduct, 10000))->toBeFalse();
});
