<?php

declare(strict_types=1);

use App\Actions\Events\ReportLegacyPublicEventMedia;
use App\Models\Costume;
use App\Models\Course;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Support\MediaDisks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('registers media collections on Costume', function () {
    $costume = Costume::factory()->create();

    $costume->addMedia(UploadedFile::fake()->image('photo.jpg'))
        ->toMediaCollection('images');

    expect($costume->getMedia('images'))->toHaveCount(1)
        ->and($costume->getFirstMedia('images')->disk)->toBe(MediaDisks::public());
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
        ->and($event->getFirstMedia('images')->disk)->toBe(MediaDisks::private())
        ->and($event->getMedia('documents'))->toHaveCount(1)
        ->and($event->getFirstMedia('documents')->disk)->toBe(MediaDisks::private());
});

it('reports legacy public event media without moving or deleting it', function (): void {
    Exceptions::fake(RuntimeException::class);
    $event = Event::factory()->create();
    $media = $event
        ->addMedia(UploadedFile::fake()->image('legacy-event.jpg'))
        ->toMediaCollection('images', MediaDisks::public());

    expect(app(ReportLegacyPublicEventMedia::class)->handle())->toBe(1)
        ->and($media->refresh()->disk)->toBe(MediaDisks::public())
        ->and($event->getMedia('images'))->toHaveCount(1);

    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), "Media: #{$media->id} event:{$event->id}")
            && str_contains($exception->getMessage(), 'must be moved manually'),
    );
});

it('does not report event media already stored privately', function (): void {
    Exceptions::fake(RuntimeException::class);
    $event = Event::factory()->create();
    $event
        ->addMedia(UploadedFile::fake()->image('private-event.jpg'))
        ->toMediaCollection('images');

    expect(app(ReportLegacyPublicEventMedia::class)->handle())->toBe(0);

    Exceptions::assertNothingReported();
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
        ->and($product->getFirstMedia('images')->disk)->toBe(MediaDisks::public())
        ->and($product->getMedia('documents'))->toHaveCount(1)
        ->and($product->getFirstMedia('documents')->disk)->toBe(MediaDisks::private())
        ->and($product->getMedia('videos'))->toHaveCount(1)
        ->and($product->getFirstMedia('videos')->disk)->toBe(MediaDisks::private());
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
        ->and($course->getFirstMedia('images')->disk)->toBe(MediaDisks::public())
        ->and($course->getMedia('documents'))->toHaveCount(1)
        ->and($course->getFirstMedia('documents')->disk)->toBe(MediaDisks::private())
        ->and($course->getMedia('videos'))->toHaveCount(1)
        ->and($course->getFirstMedia('videos')->disk)->toBe(MediaDisks::private());
});

it('registers media collections on User', function () {
    $user = User::factory()->create();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg'))
        ->toMediaCollection('avatars');
    $user->addMedia(UploadedFile::fake()->image('staff.jpg'))
        ->toMediaCollection('staff-photo');

    expect($user->getMedia('avatars'))->toHaveCount(1)
        ->and($user->getFirstMedia('avatars')->disk)->toBe(MediaDisks::private())
        ->and($user->getMedia('staff-photo'))->toHaveCount(1)
        ->and($user->getFirstMedia('staff-photo')->disk)->toBe(MediaDisks::private());
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
