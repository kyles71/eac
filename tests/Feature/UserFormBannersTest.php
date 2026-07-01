<?php

declare(strict_types=1);

use App\Enums\FormTypes;
use App\Filament\User\Pages\CheckoutSuccess;
use App\Filament\User\Widgets\UserBanners;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\ManagedBanner;
use App\Models\Order;
use App\Models\Student;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('shows dedicated waiver banners before the generic forms fallback', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiverForm = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $genericForm = Form::factory()->create(['form_type' => FormTypes::ShowcaseParticipation]);

    FormUser::factory()->forStudent($student)->unsigned()->create([
        'form_id' => $waiverForm->id,
        'user_id' => auth()->id(),
    ]);
    FormUser::factory()->forStudent($student)->unsigned()->create([
        'form_id' => $genericForm->id,
        'user_id' => auth()->id(),
    ]);

    $this->get('/dancefam')
        ->assertOk()
        ->assertSeeText('Waivers Needed')
        ->assertSeeText('The following students need waivers signed: '.$student->first_name)
        ->assertSeeText('Forms Needed')
        ->assertSeeText('You have 1 form(s) that need to be completed.');
});

it('does not render global banners on the checkout success page', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiverForm = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);

    Enrollment::factory()->create([
        'user_id' => auth()->id(),
        'student_id' => null,
    ]);
    FormUser::factory()->forStudent($student)->unsigned()->create([
        'form_id' => $waiverForm->id,
        'user_id' => auth()->id(),
    ]);
    ManagedBanner::factory()
        ->forScope(CheckoutSuccess::class)
        ->create([
            'title' => 'Checkout success notice',
            'message' => 'This managed banner is scoped to the order confirmation page.',
        ]);

    $this->get(CheckoutSuccess::getUrl().'?order_id='.$order->id)
        ->assertOk()
        ->assertDontSeeText('Complete Enrollments')
        ->assertDontSeeText('Waivers Needed')
        ->assertDontSeeText('Forms Needed')
        ->assertSeeText('Checkout success notice')
        ->assertSeeText('This managed banner is scoped to the order confirmation page.');
});

it('refreshes enrollment and form banners without a page navigation', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addMonth(),
        'end_time' => now()->addMonth()->addHour(),
    ]);
    $form = Form::factory()->create(['form_type' => FormTypes::StudentWaiver]);
    $course->forms()->attach($form);
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'student_id' => null,
    ]);

    $component = livewire(UserBanners::class)
        ->assertSee('Complete Enrollments')
        ->assertDontSee('Waivers Needed');

    $enrollment->update(['student_id' => $student->id]);

    $component
        ->call('refreshBanners')
        ->assertDontSee('Complete Enrollments')
        ->assertSee('Waivers Needed')
        ->assertSee($student->first_name);
});
