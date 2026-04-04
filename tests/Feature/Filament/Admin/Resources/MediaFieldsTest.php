<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Costumes\Pages\ListCostumes;
use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Products\Pages\ViewProduct;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\Course;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('has images column on costumes table', function () {
    livewire(ListCostumes::class)
        ->assertTableColumnExists('images');
});

it('has avatar column on users table', function () {
    livewire(ListUsers::class)
        ->assertTableColumnExists('avatar');
});

it('shows avatar on user view page', function () {
    $user = User::factory()->create();

    livewire(ViewUser::class, ['record' => $user->id])
        ->assertOk();
});

it('shows media entries on product view page', function () {
    $product = Product::factory()->create();

    livewire(ViewProduct::class, ['record' => $product->id])
        ->assertOk();
});

it('shows media entries on course view page', function () {
    $course = Course::factory()->create();

    livewire(ViewCourse::class, ['record' => $course->id])
        ->assertOk();
});

it('shows media entries on event view page', function () {
    $event = Event::factory()->create();

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertOk();
});
