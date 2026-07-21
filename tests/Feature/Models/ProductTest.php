<?php

declare(strict_types=1);

use App\Enums\DashboardAudience;
use App\Enums\ProductAvailabilityStatus;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\ProductQuestion;
use App\Models\Student;
use App\Models\User;
use App\Support\MediaDisks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

it('scopes available products', function () {
    $active = Product::factory()->create(['is_active' => true, 'price' => 5000]);
    $inactive = Product::factory()->inactive()->create();
    $zeroPriced = Product::factory()->create(['is_active' => true, 'price' => 0]);
    $nullPriced = Product::factory()->create(['is_active' => true, 'price' => null]);
    $scheduled = Product::factory()->availableFrom(now()->addDay())->create(['price' => 5000]);
    $expired = Product::factory()->availableUntil(now()->subMinute())->create(['price' => 5000]);
    $customGiftCardType = GiftCardType::factory()
        ->customAmount(500)
        ->create();
    $customGiftCard = Product::factory()
        ->forGiftCardType($customGiftCardType)
        ->create(['is_active' => true, 'price' => null]);

    $results = Product::query()->available()->get();

    expect($results->pluck('id')->toArray())->toContain($active->id)
        ->and($results->pluck('id')->toArray())->toContain($customGiftCard->id)
        ->and($results->pluck('id')->toArray())->not->toContain($inactive->id)
        ->and($results->pluck('id')->toArray())->not->toContain($zeroPriced->id)
        ->and($results->pluck('id')->toArray())->not->toContain($nullPriced->id)
        ->and($results->pluck('id')->toArray())->not->toContain($scheduled->id)
        ->and($results->pluck('id')->toArray())->not->toContain($expired->id);
});

it('formats price correctly', function () {
    $product = Product::factory()->create(['price' => 15099]);

    expect($product->formattedPrice())->toBe('$150.99');
});

it('derives valid pricing for name your price gift card products', function () {
    $user = User::factory()->create();
    $customGiftCardType = GiftCardType::factory()
        ->customAmount(500)
        ->create();
    $customGiftCard = Product::factory()
        ->forGiftCardType($customGiftCardType)
        ->create(['price' => null]);
    $missingFixedPrice = Product::factory()->create(['price' => null]);
    $invalidCustomGiftCardType = GiftCardType::factory()
        ->customAmount(50)
        ->create();
    $invalidCustomGiftCard = Product::factory()
        ->forGiftCardType($invalidCustomGiftCardType)
        ->create(['price' => null]);

    expect($customGiftCard->requiresFixedPrice())->toBeFalse()
        ->and($customGiftCard->hasValidPricing())->toBeTrue()
        ->and($customGiftCard->availabilityFor($user))->toBe(ProductAvailabilityStatus::Available)
        ->and($customGiftCard->canBePurchasedBy($user))->toBeTrue()
        ->and(Product::query()->visibleTo($user)->pluck('id')->all())->toContain($customGiftCard->id)
        ->and($missingFixedPrice->requiresFixedPrice())->toBeTrue()
        ->and($missingFixedPrice->hasValidPricing())->toBeFalse()
        ->and($missingFixedPrice->availabilityFor($user))->toBe(ProductAvailabilityStatus::InvalidPrice)
        ->and($invalidCustomGiftCard->hasValidPricing())->toBeFalse()
        ->and($invalidCustomGiftCard->availabilityFor($user))->toBe(ProductAvailabilityStatus::InvalidPrice);
});

it('knows when add to cart needs extra information', function () {
    $standardProduct = Product::factory()->create(['price' => 5000]);
    $questionProduct = Product::factory()->create(['price' => 5000]);
    ProductQuestion::factory()->for($questionProduct)->create();
    $fixedGiftCard = Product::factory()
        ->forGiftCardType(GiftCardType::factory()->denomination(5000)->create())
        ->create();
    $customGiftCard = Product::factory()
        ->forGiftCardType(GiftCardType::factory()->denomination(5000)->customAmount(500)->create())
        ->create();

    expect($standardProduct->requiresAddToCartInformation())->toBeFalse()
        ->and($standardProduct->hasPurchaserQuestions())->toBeFalse()
        ->and($questionProduct->hasPurchaserQuestions())->toBeTrue()
        ->and($questionProduct->requiresAddToCartInformation())->toBeTrue()
        ->and($fixedGiftCard->requiresAddToCartInformation())->toBeFalse()
        ->and($customGiftCard->requiresAddToCartInformation())->toBeTrue();
});

it('uses only product images by default', function () {
    Storage::fake(MediaDisks::public());

    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create();

    $product->addMedia(UploadedFile::fake()->image('product.jpg'))
        ->toMediaCollection('images');
    $course->addMedia(UploadedFile::fake()->image('course.jpg'))
        ->toMediaCollection('images');

    expect($product->galleryImages()->pluck('file_name')->all())->toBe(['product.jpg']);
});

it('can include linked item images in the gallery', function () {
    Storage::fake(MediaDisks::public());

    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create([
        'include_productable_images' => true,
    ]);

    $product->addMedia(UploadedFile::fake()->image('product.jpg'))
        ->toMediaCollection('images');
    $course->addMedia(UploadedFile::fake()->image('course.jpg'))
        ->toMediaCollection('images');

    expect($product->galleryImages()->pluck('file_name')->all())->toBe([
        'product.jpg',
        'course.jpg',
    ]);
});

it('delegates course storefront details to the linked course', function () {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    $teacher = User::factory()->create([
        'first_name' => 'Misty',
        'last_name' => 'Copeland',
    ]);
    $course = Course::factory()->create([
        'capacity' => 8,
        'guest_teacher' => null,
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::parse('2027-01-15 18:30:00', 'UTC'),
        'end_time' => Carbon::parse('2027-01-15 20:00:00', 'UTC'),
    ]);
    $course->teachers()->sync([$teacher->id]);
    $product = Product::factory()->forCourse($course)->create();

    expect($product->storefrontDetails())->toMatchArray([
        'Start Time' => 'Jan 15, 2027 10:30 AM',
        'Duration' => '90 minutes',
        'Teacher' => 'Misty Copeland',
        'Available Spots' => '8',
    ]);
});

it('delegates multiple course teacher names to storefront details', function () {
    $firstTeacher = User::factory()->create([
        'first_name' => 'Alvin',
        'last_name' => 'Ailey',
    ]);
    $secondTeacher = User::factory()->create([
        'first_name' => 'Twyla',
        'last_name' => 'Tharp',
    ]);
    $course = Course::factory()->create(['guest_teacher' => null]);
    $course->teachers()->sync([$secondTeacher->id, $firstTeacher->id]);
    $product = Product::factory()->forCourse($course)->create();

    expect($product->storefrontDetails())
        ->toHaveKey('Teacher', 'Alvin Ailey, Twyla Tharp');
});

it('lets guest teacher override assigned course teachers in storefront details', function () {
    $teacher = User::factory()->create([
        'first_name' => 'Misty',
        'last_name' => 'Copeland',
    ]);
    $course = Course::factory()->create(['guest_teacher' => 'Guest Artist']);
    $course->teachers()->sync([$teacher->id]);
    $product = Product::factory()->forCourse($course)->create();

    expect($product->storefrontDetails())
        ->toHaveKey('Teacher', 'Guest Artist');
});

it('delegates gift card storefront details to the linked gift card type', function () {
    $giftCardType = GiftCardType::factory()->create([
        'denomination' => 2500,
        'restricted_to_product_type' => null,
    ]);
    $product = Product::factory()->forGiftCardType($giftCardType)->create();

    expect($product->storefrontDetails())->toBe([
        'Denomination' => '$25.00',
        'Restrictions' => 'Unrestricted',
    ]);
});

it('checks purchase eligibility from existing enrollments', function () {
    $user = User::factory()->create();
    $requiredCourse = Course::factory()->create();
    $product = Product::factory()->create([
        'requires_course_id' => $requiredCourse->id,
    ]);

    expect($product->canBePurchasedBy($user))->toBeFalse();

    Enrollment::factory()->create([
        'course_id' => $requiredCourse->id,
        'user_id' => $user->id,
    ]);

    expect($product->canBePurchasedBy($user))->toBeTrue();
});

it('evaluates scheduled product availability boundaries', function () {
    $now = Carbon::parse('2026-07-01 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    try {
        $product = Product::factory()->create([
            'price' => 5000,
            'available_from' => $now,
            'available_until' => $now->copy()->addHour(),
        ]);

        expect($product->canBePurchasedBy(User::factory()->create()))->toBeTrue()
            ->and($product->availabilityStatus())->toBe(ProductAvailabilityStatus::Available);

        $product->update(['available_until' => $now]);

        expect($product->refresh()->canBePurchasedBy(User::factory()->create()))->toBeFalse()
            ->and($product->availabilityStatus())->toBe(ProductAvailabilityStatus::Expired);
    } finally {
        Carbon::setTestNow();
    }
});

it('grants direct user early access before the available from time', function () {
    $allowedUser = User::factory()->create();
    $blockedUser = User::factory()->create();
    $product = Product::factory()
        ->availableFrom(now()->addDay())
        ->create(['price' => 5000]);

    ProductEarlyAccessWindow::factory()
        ->for($product)
        ->create()
        ->users()
        ->attach($allowedUser);

    expect($product->refresh()->canBePurchasedBy($allowedUser))->toBeTrue()
        ->and($product->canBePurchasedBy($blockedUser))->toBeFalse()
        ->and(Product::query()->visibleTo($allowedUser)->pluck('id')->all())->toContain($product->id)
        ->and(Product::query()->visibleTo($blockedUser)->pluck('id')->all())->not->toContain($product->id);
});

it('does not grant early access before the window starts', function () {
    $user = User::factory()->create();
    $product = Product::factory()
        ->availableFrom(now()->addDays(2))
        ->create(['price' => 5000]);

    ProductEarlyAccessWindow::factory()
        ->for($product)
        ->create(['available_from' => now()->addDay()])
        ->users()
        ->attach($user);

    expect($product->refresh()->canBePurchasedBy($user))->toBeFalse()
        ->and($product->availabilityFor($user))->toBe(ProductAvailabilityStatus::Scheduled);
});

it('uses early access window start and end boundaries', function () {
    $now = Carbon::parse('2026-07-01 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    try {
        $user = User::factory()->create();
        $product = Product::factory()
            ->availableFrom($now->copy()->addDay())
            ->create(['price' => 5000]);

        $window = ProductEarlyAccessWindow::factory()
            ->for($product)
            ->create([
                'available_from' => $now,
                'available_until' => $now->copy()->addHour(),
            ]);
        $window->users()->attach($user);

        expect($product->refresh()->canBePurchasedBy($user))->toBeTrue()
            ->and($product->availabilityFor($user))->toBe(ProductAvailabilityStatus::EarlyAccess);

        Carbon::setTestNow($now->copy()->addHour());

        expect($product->refresh()->canBePurchasedBy($user))->toBeFalse()
            ->and($product->availabilityFor($user))->toBe(ProductAvailabilityStatus::Scheduled);
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps open-ended early access active until the product opens publicly or expires', function () {
    $now = Carbon::parse('2026-07-01 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    try {
        $user = User::factory()->create();
        $product = Product::factory()
            ->availableFrom($now->copy()->addDay())
            ->availableUntil($now->copy()->addDays(2))
            ->create(['price' => 5000]);

        ProductEarlyAccessWindow::factory()
            ->for($product)
            ->create([
                'available_from' => $now->copy()->subHour(),
                'available_until' => null,
            ])
            ->users()
            ->attach($user);

        expect($product->refresh()->canBePurchasedBy($user))->toBeTrue()
            ->and($product->availabilityFor($user))->toBe(ProductAvailabilityStatus::EarlyAccess);

        Carbon::setTestNow($now->copy()->addDay());

        expect($product->refresh()->canBePurchasedBy($user))->toBeTrue()
            ->and($product->availabilityFor($user))->toBe(ProductAvailabilityStatus::Available);

        Carbon::setTestNow($now->copy()->addDays(2));

        expect($product->refresh()->canBePurchasedBy($user))->toBeFalse()
            ->and($product->availabilityFor($user))->toBe(ProductAvailabilityStatus::Expired);
    } finally {
        Carbon::setTestNow();
    }
});

it('grants computed competition audience early access to current competition families', function () {
    $season = CompetitionSeason::factory()->current()->create();
    $team = CompetitionTeam::factory()->for($season, 'season')->create();
    $user = User::factory()->create();
    $student = Student::factory()->for($user)->create();
    $product = Product::factory()
        ->availableFrom(now()->addDay())
        ->withEarlyAccessAudience(DashboardAudience::CompTeam)
        ->create(['price' => 5000]);

    $student->competitionTeams()->attach($team);

    expect($product->canBePurchasedBy($user))->toBeTrue()
        ->and($product->availabilityFor($user))->toBe(ProductAvailabilityStatus::EarlyAccess)
        ->and(Product::query()->visibleTo($user)->pluck('id')->all())->toContain($product->id);
});

it('does not let early access bypass expiration or enrollment restrictions', function () {
    $user = User::factory()->create();
    $requiredCourse = Course::factory()->create();
    $expiredProduct = Product::factory()
        ->availableUntil(now()->subMinute())
        ->create(['price' => 5000]);
    $restrictedProduct = Product::factory()
        ->availableFrom(now()->addDay())
        ->create([
            'price' => 5000,
            'requires_course_id' => $requiredCourse->id,
        ]);

    ProductEarlyAccessWindow::factory()
        ->for($expiredProduct)
        ->create()
        ->users()
        ->attach($user);
    ProductEarlyAccessWindow::factory()
        ->for($restrictedProduct)
        ->create()
        ->users()
        ->attach($user);

    expect($expiredProduct->refresh()->canBePurchasedBy($user))->toBeFalse()
        ->and($expiredProduct->availabilityFor($user))->toBe(ProductAvailabilityStatus::Expired)
        ->and($restrictedProduct->refresh()->canBePurchasedBy($user))->toBeFalse()
        ->and($restrictedProduct->availabilityFor($user))->toBe(ProductAvailabilityStatus::EnrollmentRequired);
});

it('morphs to a course', function () {
    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create();

    expect($product->productable)->toBeInstanceOf(Course::class)
        ->and($product->productable->id)->toBe($course->id);
});

it('can be created without a productable', function () {
    $product = Product::factory()->create([
        'productable_type' => null,
        'productable_id' => null,
    ]);

    expect($product->productable)->toBeNull();
});
