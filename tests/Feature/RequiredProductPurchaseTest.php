<?php

declare(strict_types=1);

use App\Actions\Mail\SendRequiredProductPurchaseReminders;
use App\Enums\CourseSemester;
use App\Enums\PurchaseRequirementStatus;
use App\Filament\User\Widgets\NeedsAttention;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPurchaseReminderDelivery;
use App\Models\Student;
use App\Models\User;
use App\Services\ProductPurchaseReportService;
use App\Services\ProductPurchaseRequirementService;
use App\Services\ProductStudentExclusionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

beforeEach(function (): void {
    Carbon::setTestNow('2030-09-15 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('requires a deadline and keeps an optional reminder inside the availability window', function (): void {
    expect(fn () => Product::factory()->create([
        'is_purchase_required' => true,
        'available_until' => null,
    ]))->toThrow(ValidationException::class);

    expect(fn () => Product::factory()->create([
        'is_purchase_required' => true,
        'available_from' => '2030-09-10 09:00:00',
        'purchase_reminder_on' => '2030-09-09',
        'available_until' => '2030-09-30 17:00:00',
    ]))->toThrow(ValidationException::class);

    $product = Product::factory()->purchaseRequired()->create([
        'purchase_reminder_on' => null,
    ]);

    expect($product->is_purchase_required)->toBeTrue()
        ->and($product->purchase_reminder_on)->toBeNull();
});

it('uses assigned students in the current term for a blank required audience', function (): void {
    $currentTerm = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2030)->create();
    $pastTerm = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2029)->create();
    $currentCourse = Course::factory()->for($currentTerm, 'academicTerm')->create();
    $pastCourse = Course::factory()->for($pastTerm, 'academicTerm')->create();
    $includedHousehold = User::factory()->create();
    $openSeatHousehold = User::factory()->create();
    $pastHousehold = User::factory()->create();
    $student = Student::factory()->for($includedHousehold)->create();
    Enrollment::factory()->for($currentCourse)->for($includedHousehold)->withStudent($student)->create();
    Enrollment::factory()->for($currentCourse)->for($openSeatHousehold)->create();
    Enrollment::factory()->for($pastCourse)->for($pastHousehold)->withStudent(Student::factory()->for($pastHousehold)->create())->create();
    $product = Product::factory()->purchaseRequired()->create();

    expect($product->canBePurchasedBy($includedHousehold))->toBeTrue()
        ->and($product->canBePurchasedBy($openSeatHousehold))->toBeFalse()
        ->and($product->canBePurchasedBy($pastHousehold))->toBeFalse()
        ->and(app(ProductPurchaseRequirementService::class)->rowForUser($product, $includedHousehold)['required'])->toBe(1)
        ->and(app(ProductPurchaseRequirementService::class)->rowForUser($product, $openSeatHousehold))->toBeNull();

    app(ProductStudentExclusionService::class)->sync($product, [$student->id]);

    expect($product->refresh()->canBePurchasedBy($includedHousehold))->toBeFalse()
        ->and(app(ProductPurchaseRequirementService::class)->rowForUser($product, $includedHousehold))->toBeNull();
});

it('lets direct household assignment survive a student exclusion', function (): void {
    $term = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2030)->create();
    $course = Course::factory()->for($term, 'academicTerm')->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    Enrollment::factory()->for($course)->for($household)->withStudent($student)->create();
    $product = Product::factory()->purchaseRequired()->create();
    $product->requiredCourses()->attach($course);
    app(ProductStudentExclusionService::class)->sync($product, [$student->id]);

    expect($product->refresh()->canBePurchasedBy($household))->toBeFalse();

    $product->assignedUsers()->attach($household);
    $row = app(ProductPurchaseRequirementService::class)->rowForUser($product->refresh(), $household);

    expect($product->canBePurchasedBy($household))->toBeTrue()
        ->and($row['required'])->toBe(1)
        ->and($row['targets'])->toBe(['Direct household assignment']);
});

it('counts one required unit per ordinary household and completed orders only', function (): void {
    $household = User::factory()->create();
    $product = Product::factory()->purchaseRequired()->create();
    $product->assignedUsers()->attach($household);
    OrderItem::factory()->for(Order::factory()->for($household))->for($product)->create(['quantity' => 2]);

    $beforePurchase = app(ProductPurchaseRequirementService::class)->rowForUser($product, $household);

    expect($beforePurchase['required'])->toBe(1)
        ->and($beforePurchase['purchased'])->toBe(0)
        ->and($beforePurchase['status'])->toBe(PurchaseRequirementStatus::NotOrdered);

    OrderItem::factory()->for(Order::factory()->completed()->for($household))->for($product)->create(['quantity' => 1]);
    $afterPurchase = app(ProductPurchaseRequirementService::class)->rowForUser($product, $household);

    expect($afterPurchase['remaining'])->toBe(0)
        ->and($afterPurchase['status'])->toBe(PurchaseRequirementStatus::Complete);

    $response = app(ProductPurchaseReportService::class)->download($product);
    ob_start();

    try {
        ($response->getCallback())();
        $csv = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($csv)->toContain('Purchase Deadline')
        ->toContain('Required Quantity')
        ->toContain('Complete');
});

it('clears requirement-only state when a product becomes optional', function (): void {
    $term = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2030)->create();
    $course = Course::factory()->for($term, 'academicTerm')->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    Enrollment::factory()->for($course)->for($household)->withStudent($student)->create();
    $product = Product::factory()->purchaseRequired()->create(['purchase_reminder_on' => today()]);
    app(ProductStudentExclusionService::class)->sync($product, [$student->id]);
    ProductPurchaseReminderDelivery::query()->create([
        'product_id' => $product->id,
        'user_id' => $household->id,
        'reminder_on' => today(),
        'sent_at' => now(),
    ]);

    $product->update(['is_purchase_required' => false]);

    expect($product->refresh()->purchase_reminder_on)->toBeNull()
        ->and($product->excludedStudents()->count())->toBe(0)
        ->and($product->purchaseReminderDeliveries()->count())->toBe(0);
});

it('registers and sends a deduplicated household digest for newly due products', function (): void {
    Mail::fake();
    $definition = app(EmailTypeRegistry::class)->get('required-product-purchase-reminder');
    $term = AcademicTerm::factory()->forSemester(CourseSemester::Fall, 2030)->create();
    $course = Course::factory()->for($term, 'academicTerm')->create();
    $household = User::factory()->create(['first_name' => 'Jamie', 'email' => 'required@example.com']);
    $student = Student::factory()->for($household)->create();
    Enrollment::factory()->for($course)->for($household)->withStudent($student)->create();
    $first = Product::factory()->purchaseRequired()->create([
        'name' => 'Required Shoes',
        'purchase_reminder_on' => today(),
    ]);
    $second = Product::factory()->purchaseRequired()->create([
        'name' => 'Required Tights',
        'purchase_reminder_on' => today(),
    ]);

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->slotsByMergeTag()))->toBe(['slot.required-products'])
        ->and(app(SendRequiredProductPurchaseReminders::class)->handle())->toBe([
            'users_reminded' => 1,
            'products_marked' => 2,
        ])->and(app(SendRequiredProductPurchaseReminders::class)->handle())->toBe([
            'users_reminded' => 0,
            'products_marked' => 0,
        ])->and(ProductPurchaseReminderDelivery::query()->count())->toBe(2);

    Mail::assertQueued(ManagedMail::class, 1);
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'required-product-purchase-reminder'
        && $mail->hasTo('required@example.com')
        && str_contains($mail->getRenderedEmail()->html, 'Required Shoes')
        && str_contains($mail->getRenderedEmail()->html, 'Required Tights'));

    app(ManagedTemplateRepository::class)->saveOverride('required-product-purchase-reminder', ['is_active' => false]);
    $first->update(['purchase_reminder_on' => today()->subDay()]);

    expect($first->purchaseReminderDeliveries()->count())->toBe(0)
        ->and(app(SendRequiredProductPurchaseReminders::class)->handle())->toBe([
            'users_reminded' => 0,
            'products_marked' => 0,
        ])
        ->and($first->purchaseReminderDeliveries()->count())->toBe(0);
});

it('shows portal reminders only from the reminder date through the purchase deadline', function (): void {
    $household = User::factory()->create();
    $product = Product::factory()->purchaseRequired()->create([
        'name' => 'Required Leotard',
        'purchase_reminder_on' => today()->addDay(),
        'available_until' => now()->addWeek(),
    ]);
    $product->assignedUsers()->attach($household);
    $this->actingAs($household);

    expect(collect(app(NeedsAttention::class)->tasks())->pluck('title'))
        ->not->toContain('Required purchase: Required Leotard');

    $product->update(['purchase_reminder_on' => today()->subDay()]);

    expect(collect(app(NeedsAttention::class)->tasks())->pluck('title'))
        ->toContain('Required purchase: Required Leotard');

    $product->update(['available_until' => now()->subHour()]);

    expect(collect(app(NeedsAttention::class)->tasks())->pluck('title'))
        ->not->toContain('Required purchase: Required Leotard');
});

it('runs purchase reminders through the scheduled command', function (): void {
    Mail::fake();
    $household = User::factory()->create(['email' => 'command@example.com']);
    $product = Product::factory()->purchaseRequired()->create([
        'purchase_reminder_on' => today()->subDay(),
    ]);
    $product->assignedUsers()->attach($household);

    $this->artisan('products:send-purchase-reminders')
        ->expectsOutput('Reminded 1 user about 1 required product.')
        ->assertSuccessful();

    Mail::assertQueued(ManagedMail::class, 1);
});
