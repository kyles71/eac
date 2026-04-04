<?php

declare(strict_types=1);

use App\Models\Costume;
use App\Models\Course;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('registers media collections on Costume', function () {
    $costume = Costume::factory()->create();

    $costume->addMedia(UploadedFile::fake()->image('photo.jpg'))
        ->toMediaCollection('images');

    expect($costume->getMedia('images'))->toHaveCount(1);
});

it('allows multiple images on Costume', function () {
    $costume = Costume::factory()->create();

    $costume->addMedia(UploadedFile::fake()->image('photo1.jpg'))
        ->toMediaCollection('images');
    $costume->addMedia(UploadedFile::fake()->image('photo2.jpg'))
        ->toMediaCollection('images');

    expect($costume->getMedia('images'))->toHaveCount(2);
});

it('registers media collections on Event', function () {
    $event = Event::factory()->create();

    $event->addMedia(UploadedFile::fake()->image('event.jpg'))
        ->toMediaCollection('images');
    $event->addMedia(UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf'))
        ->toMediaCollection('documents');

    expect($event->getMedia('images'))->toHaveCount(1)
        ->and($event->getMedia('documents'))->toHaveCount(1);
});

it('registers media collections on Product', function () {
    $product = Product::factory()->create();

    $product->addMedia(UploadedFile::fake()->image('gallery.jpg'))
        ->toMediaCollection('images');
    $product->addMedia(UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'))
        ->toMediaCollection('documents');
    $product->addMedia(UploadedFile::fake()->create('demo.mp4', 500, 'video/mp4'))
        ->toMediaCollection('videos');

    expect($product->getMedia('images'))->toHaveCount(1)
        ->and($product->getMedia('documents'))->toHaveCount(1)
        ->and($product->getMedia('videos'))->toHaveCount(1);
});

it('registers media collections on Course', function () {
    $course = Course::factory()->create();

    $course->addMedia(UploadedFile::fake()->image('course.jpg'))
        ->toMediaCollection('images');
    $course->addMedia(UploadedFile::fake()->create('syllabus.pdf', 100, 'application/pdf'))
        ->toMediaCollection('documents');
    $course->addMedia(UploadedFile::fake()->create('intro.mp4', 500, 'video/mp4'))
        ->toMediaCollection('videos');

    expect($course->getMedia('images'))->toHaveCount(1)
        ->and($course->getMedia('documents'))->toHaveCount(1)
        ->and($course->getMedia('videos'))->toHaveCount(1);
});

it('registers media collections on User', function () {
    $user = User::factory()->create();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg'))
        ->toMediaCollection('avatars');
    $user->addMedia(UploadedFile::fake()->image('staff.jpg'))
        ->toMediaCollection('staff-photo');

    expect($user->getMedia('avatars'))->toHaveCount(1)
        ->and($user->getMedia('staff-photo'))->toHaveCount(1);
});

it('enforces single file on User avatar collection', function () {
    $user = User::factory()->create();

    $user->addMedia(UploadedFile::fake()->image('old-avatar.jpg'))
        ->toMediaCollection('avatars');
    $user->addMedia(UploadedFile::fake()->image('new-avatar.jpg'))
        ->toMediaCollection('avatars');

    expect($user->getMedia('avatars'))->toHaveCount(1)
        ->and($user->getFirstMedia('avatars')->file_name)->toBe('new-avatar.jpg');
});

it('enforces single file on User staff-photo collection', function () {
    $user = User::factory()->create();

    $user->addMedia(UploadedFile::fake()->image('old-staff.jpg'))
        ->toMediaCollection('staff-photo');
    $user->addMedia(UploadedFile::fake()->image('new-staff.jpg'))
        ->toMediaCollection('staff-photo');

    expect($user->getMedia('staff-photo'))->toHaveCount(1)
        ->and($user->getFirstMedia('staff-photo')->file_name)->toBe('new-staff.jpg');
});
