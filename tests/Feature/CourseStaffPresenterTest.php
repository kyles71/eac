<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\User;
use App\Support\Filament\CourseStaffPresenter;
use App\Support\MediaDisks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(MediaDisks::private());
});

it('renders separate tooltips using only available staff profile information', function (): void {
    $bioOnly = User::factory()->create([
        'first_name' => '<Bio>',
        'last_name' => 'Only',
        'staff_bio' => 'Biography <script>alert("unsafe")</script>',
    ]);
    $photoOnly = User::factory()->create([
        'first_name' => 'Photo',
        'last_name' => 'Only',
        'staff_bio' => null,
    ]);
    $complete = User::factory()->create([
        'first_name' => 'Complete',
        'last_name' => 'Profile',
        'staff_bio' => 'Complete biography.',
    ]);
    $empty = User::factory()->create([
        'first_name' => 'Empty',
        'last_name' => 'Profile',
        'staff_bio' => null,
    ]);

    $photoOnly->addMedia(UploadedFile::fake()->image('photo-only.jpg'))
        ->toMediaCollection('staff-photo');
    $complete->addMedia(UploadedFile::fake()->image('complete.jpg'))
        ->toMediaCollection('staff-photo');

    $course = Course::factory()->create();
    $course->teachers()->sync([$bioOnly->id, $photoOnly->id, $complete->id, $empty->id]);

    $html = CourseStaffPresenter::render($course->refresh())?->toHtml();

    expect($html)
        ->not->toBeNull()
        ->and(mb_substr_count((string) $html, 'x-tooltip='))->toBe(3)
        ->and($html)->toContain('&lt;Bio&gt; Only')
        ->and($html)->toContain('Biography \\u0026lt;script\\u0026gt;alert')
        ->and($html)->not->toContain('<script>alert')
        ->and($html)->toContain('photo-only.jpg')
        ->and($html)->toContain('complete.jpg')
        ->and($html)->toContain('Complete biography.')
        ->and($html)->toContain('Empty Profile')
        ->and(preg_match('/<span\s*>Empty Profile<\/span>/', (string) $html))->toBe(1);
});

it('renders a guest teacher as escaped plain text without a tooltip', function (): void {
    $course = Course::factory()->create([
        'guest_teacher' => '<Guest Teacher>',
    ]);

    $html = CourseStaffPresenter::render($course)?->toHtml();

    expect($html)
        ->toBe('&lt;Guest Teacher&gt;')
        ->not->toContain('x-tooltip');
});
