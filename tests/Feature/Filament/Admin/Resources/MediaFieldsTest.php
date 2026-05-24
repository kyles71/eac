<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\Course;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Facades\Filament;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('has images column on costumes table', function () {
    livewire(ListCostumes::class)
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
            && $entry->getVisibility() === 'private')
        ->assertSchemaComponentExists('staff_photo', 'infolist', fn (SpatieMediaLibraryImageEntry $entry): bool => $entry->getDiskName() === MediaDisks::private()
            && $entry->getVisibility() === 'private');
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
