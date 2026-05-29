<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\User;
use App\Support\MediaDisks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

it('scopes available products', function () {
    $active = Product::factory()->create(['is_active' => true, 'price' => 5000]);
    $inactive = Product::factory()->inactive()->create();
    $zeroPriced = Product::factory()->create(['is_active' => true, 'price' => 0]);

    $results = Product::query()->available()->get();

    expect($results->pluck('id')->toArray())->toContain($active->id)
        ->and($results->pluck('id')->toArray())->not->toContain($inactive->id)
        ->and($results->pluck('id')->toArray())->not->toContain($zeroPriced->id);
});

it('formats price correctly', function () {
    $product = Product::factory()->create(['price' => 15099]);

    expect($product->formattedPrice())->toBe('$150.99');
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
    $teacher = User::factory()->create([
        'first_name' => 'Misty',
        'last_name' => 'Copeland',
    ]);
    $course = Course::factory()->create([
        'capacity' => 8,
        'duration' => 90,
        'start_time' => Carbon::parse('2027-01-15 18:30:00'),
        'teacher_id' => $teacher->id,
        'guest_teacher' => null,
    ]);
    $product = Product::factory()->forCourse($course)->create();

    expect($product->storefrontDetails())->toMatchArray([
        'Start Time' => 'Jan 15, 2027 6:30 PM',
        'Duration' => '90 minutes',
        'Teacher' => 'Misty Copeland',
        'Available Spots' => '8',
    ]);
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
