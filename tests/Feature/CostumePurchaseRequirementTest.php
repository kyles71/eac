<?php

declare(strict_types=1);

use App\Enums\CostumeOrderStatus;
use App\Filament\User\Widgets\NeedsAttention;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use App\Services\CostumePurchaseReportService;
use App\Services\CostumePurchaseRequirementService;
use App\Services\ProductStudentAssignmentService;

it('tracks sibling costume requirements as partial until the full quantity is ordered', function (): void {
    $household = User::factory()->create();
    $course = Course::factory()->create();
    $firstStudent = Student::factory()->for($household)->create(['first_name' => 'Anna']);
    $secondStudent = Student::factory()->for($household)->create(['first_name' => 'Bella']);
    Enrollment::factory()->for($course)->for($household)->withStudent($firstStudent)->create();
    Enrollment::factory()->for($course)->for($household)->withStudent($secondStudent)->create();
    $product = Product::factory()->forCostume(Costume::factory()->for($course)->create())->create();
    app(ProductStudentAssignmentService::class)->sync($product, [$firstStudent->id, $secondStudent->id]);
    $order = Order::factory()->completed()->for($household)->create();
    OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1]);

    $row = app(CostumePurchaseRequirementService::class)->rowForUser($product, $household);

    expect($row)->not->toBeNull()
        ->and($row['targets'])->toHaveCount(2)
        ->and($row['required'])->toBe(2)
        ->and($row['purchased'])->toBe(1)
        ->and($row['remaining'])->toBe(1)
        ->and($row['status'])->toBe(CostumeOrderStatus::Partial)
        ->and($row['order_numbers'])->toBe([$order->id]);
});

it('counts each open enrollment and each distinct assigned student for course-wide costumes', function (): void {
    $household = User::factory()->create();
    $course = Course::factory()->create();
    $student = Student::factory()->for($household)->create();
    Enrollment::factory()->for($course)->for($household)->withStudent($student)->create();
    Enrollment::factory()->for($course)->for($household)->withStudent($student)->create();
    Enrollment::factory(2)->for($course)->for($household)->create();
    $product = Product::factory()->forCostume(Costume::factory()->for($course)->create())->create();

    $row = app(CostumePurchaseRequirementService::class)->rowForUser($product, $household);

    expect($row['required'])->toBe(3)
        ->and($row['targets'])->toContain($student->fullName, 'Unassigned enrollment');
});

it('ignores incomplete orders and exports order status rows', function (): void {
    $household = User::factory()->create();
    $course = Course::factory()->create();
    Enrollment::factory()->for($course)->for($household)->create();
    $costume = Costume::factory()->for($course)->create(['name' => 'Tap Costume']);
    $product = Product::factory()->forCostume($costume)->create(['order_due_on' => '2026-10-01']);
    OrderItem::factory()->for(Order::factory()->for($household))->for($product)->create(['quantity' => 1]);

    $response = app(CostumePurchaseReportService::class)->downloadRequirements($costume);
    ob_start();

    try {
        ($response->getCallback())();
        $csv = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($csv)->toContain('Remaining Quantity')
        ->toContain('Tap Costume')
        ->toContain('Not Ordered');
});

it('shows and clears costume order reminders as completed quantities are purchased', function (): void {
    $household = User::factory()->create();
    $course = Course::factory()->create();
    Enrollment::factory()->for($course)->for($household)->create();
    $product = Product::factory()->forCostume(Costume::factory()->for($course)->create([
        'name' => 'Jazz Costume',
    ]))->create(['order_due_on' => now()->addWeek()]);
    $this->actingAs($household);

    expect(collect(app(NeedsAttention::class)->tasks())->pluck('title')->all())
        ->toContain('Costume order needed: Jazz Costume');

    OrderItem::factory()
        ->for(Order::factory()->completed()->for($household))
        ->for($product)
        ->create(['quantity' => 1]);

    expect(collect(app(NeedsAttention::class)->tasks())->pluck('title')->all())
        ->not->toContain('Costume order needed: Jazz Costume');
});
