<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Gear\Pages\ListGear;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\Course;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('has images column on gear table', function () {
    livewire(ListGear::class)
        ->assertTableColumnExists('images', fn (SpatieMediaLibraryImageColumn $column): bool => $column->getDiskName() === MediaDisks::public()
            && $column->getVisibility() === 'public');
});

it('has avatar column on users table', function () {
    livewire(ListUsers::class)
        ->assertTableColumnExists('avatar', fn (SpatieMediaLibraryImageColumn $column): bool => $column->getDiskName() === MediaDisks::private()
            && $column->getVisibility() === 'private');
});

it('shows avatar on user view page', function () {
    $user = User::factory()->create();

    livewire(ViewUser::class, ['record' => $user->id])
        ->assertOk()
        ->assertSchemaComponentExists('avatar', 'infolist', fn (SpatieMediaLibraryImageEntry $entry): bool => $entry->getDiskName() === MediaDisks::private()
            && $entry->getVisibility() === 'private');
});

it('shows staff photo on user view page only if user is a staff member', function () {
    $staff = User::factory()->isTeacher()->create();
    $user = User::factory()->create();

    livewire(ViewUser::class, ['record' => $user->id])
        ->assertOk()
        ->assertSchemaComponentHidden('staff_photo', 'infolist');

    livewire(ViewUser::class, ['record' => $staff->id])
        ->assertOk()
        ->assertSchemaComponentVisible('staff_photo', 'infolist');
});

it('shows media entries on product view page', function () {
    $product = Product::factory()->create();

    livewire(ViewProduct::class, ['record' => $product->id])
        ->assertOk()
        ->assertSchemaComponentExists('images', 'infolist', fn (SpatieMediaLibraryImageEntry $entry): bool => $entry->getDiskName() === MediaDisks::public()
            && $entry->getVisibility() === 'public');
});

it('shows media entries on course view page', function () {
    $course = Course::factory()->create();

    livewire(ViewCourse::class, ['record' => $course->id])
        ->assertOk()
        ->assertSchemaComponentExists('course_images', 'infolist', fn (SpatieMediaLibraryImageEntry $entry): bool => $entry->getDiskName() === MediaDisks::public()
            && $entry->getVisibility() === 'public');
});

it('shows media entries on event view page', function () {
    $event = Event::factory()->create();

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertOk()
        ->assertSchemaComponentExists('images', 'infolist', fn (SpatieMediaLibraryImageEntry $entry): bool => $entry->getDiskName() === MediaDisks::public()
            && $entry->getVisibility() === 'public');
});

it('applies default upload size limits to image fields', function () {
    $teacherRole = Role::findOrCreate('teacher');

    livewire(ListGear::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentExists('images', null, fn (SpatieMediaLibraryFileUpload $field): bool => $field->getMaxSize() === config('app.file_uploads.max_size_kilobytes'));

    livewire(ListUsers::class)
        ->mountAction(CreateAction::class)
        ->fillForm(['roles' => [$teacherRole->id]])
        ->assertSchemaComponentExists('avatar', null, fn (SpatieMediaLibraryFileUpload $field): bool => $field->getMaxSize() === config('app.file_uploads.max_size_kilobytes'))
        ->assertSchemaComponentExists('staff_photo', null, fn (SpatieMediaLibraryFileUpload $field): bool => $field->getMaxSize() === config('app.file_uploads.max_size_kilobytes'));
});

it('applies larger upload size limits to video fields while keeping documents at the default size', function () {
    livewire(ListProducts::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentExists('documents', null, fn (SpatieMediaLibraryFileUpload $field): bool => $field->getMaxSize() === config('app.file_uploads.max_size_kilobytes'))
        ->assertSchemaComponentExists('videos', null, fn (SpatieMediaLibraryFileUpload $field): bool => $field->getMaxSize() === config('app.file_uploads.video_max_size_kilobytes'));
});
