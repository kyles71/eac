<?php

declare(strict_types=1);

use App\Filament\User\Pages\HeldClasses;
use App\Models\Course;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
    $this->family = User::factory()->create();
    $this->actingAs($this->family);
});

it('shows active held classes and their locked prices', function (): void {
    $course = Course::factory()->create(['name' => 'Held Ballet']);
    Product::factory()->forCourse($course)->create(['price' => 17_500]);
    $hold = CourseHold::factory()->create([
        'user_id' => $this->family->id,
        'expires_at' => now()->addDays(2),
    ]);
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
        'course_id' => $course->id,
        'locked_unit_price' => 15_000,
    ]);

    livewire(HeldClasses::class)
        ->assertOk()
        ->assertSee('Held Ballet')
        ->assertSee('$150.00')
        ->assertSee('Add All Held Seats to Cart');
});

it('does not expose another family or an expired hold', function (): void {
    $course = Course::factory()->create(['name' => 'Private Jazz']);
    Product::factory()->forCourse($course)->create(['price' => 10_000]);

    foreach ([
        ['user_id' => User::factory()->create()->id, 'expires_at' => now()->addDay()],
        ['user_id' => $this->family->id, 'expires_at' => now()->subMinute()],
    ] as $attributes) {
        $hold = CourseHold::factory()->create($attributes);
        CourseHoldSeat::factory()->create([
            'course_hold_id' => $hold->id,
            'course_id' => $course->id,
        ]);
    }

    livewire(HeldClasses::class)
        ->assertOk()
        ->assertSee('No active class holds')
        ->assertDontSee('Private Jazz');
});
