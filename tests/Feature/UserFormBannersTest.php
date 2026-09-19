<?php

declare(strict_types=1);

use App\Filament\User\Pages\CheckoutSuccess;
use App\Filament\User\Widgets\UserBanners;
use App\Models\Course;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\FormVersion;
use App\Models\Installment;
use App\Models\ManagedBanner;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

function createBannerForm(string $key, string $name = 'Required Form'): Form
{
    $form = Form::factory()->create([
        'name' => $name,
        'key' => $key,
    ]);

    FormVersion::factory()
        ->for($form)
        ->published()
        ->create();

    return $form->refresh();
}

function createBannerAssignment(Form $form, Student $student): FormAssignment
{
    return FormAssignment::factory()->create([
        'form_id' => $form->id,
        'form_version_id' => $form->refresh()->currentVersion->id,
        'respondent_type' => $student->user->getMorphClass(),
        'respondent_id' => $student->user_id,
        'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id,
    ]);
}

it('consolidates required forms into one compact attention summary', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiverForm = createBannerForm('student-waiver');
    $genericForm = createBannerForm('showcase-participation');

    createBannerAssignment($waiverForm, $student);
    createBannerAssignment($genericForm, $student);

    livewire(UserBanners::class)
        ->assertSee('2 items need attention')
        ->assertSee('2 forms')
        ->assertSee('Review')
        ->assertSee('Items Needing Attention')
        ->assertSee('Forms')
        ->assertSee($waiverForm->name)
        ->assertSee($genericForm->name)
        ->assertSee($student->first_name)
        ->assertDontSee('Waivers Needed')
        ->assertDontSee('Forms Needed');
});

it('does not render global banners on the checkout success page', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $waiverForm = createBannerForm('student-waiver');
    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);

    Enrollment::factory()->create([
        'user_id' => auth()->id(),
        'student_id' => null,
    ]);
    createBannerAssignment($waiverForm, $student);
    ManagedBanner::factory()
        ->forScope(CheckoutSuccess::class)
        ->create([
            'title' => 'Checkout success notice',
            'message' => 'This managed banner is scoped to the order confirmation page.',
        ]);

    $this->get(CheckoutSuccess::getUrl().'?order_id='.$order->id)
        ->assertOk()
        ->assertDontSeeText('items need attention')
        ->assertDontSeeText('item needs attention')
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
    $form = createBannerForm('student-waiver');
    $course->forms()->attach($form);
    $enrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
        'student_id' => null,
    ]);

    $component = livewire(UserBanners::class)
        ->assertSee('1 item needs attention')
        ->assertSee('1 class seat')
        ->assertDontSee('1 form');

    $enrollment->update(['student_id' => $student->id]);

    $component
        ->call('refreshBanners')
        ->assertSee('1 item needs attention')
        ->assertDontSee('1 class seat')
        ->assertSee('1 form')
        ->assertSee($student->first_name);
});

it('shows only authorized household tasks', function (): void {
    $ownStudent = Student::factory()->create(['user_id' => auth()->id()]);
    $otherHousehold = User::factory()->create();
    $otherStudent = Student::factory()->create(['user_id' => $otherHousehold->id]);
    $ownForm = createBannerForm('own-form', 'My Required Form');
    $otherForm = createBannerForm('other-form', 'Another Household Form');

    createBannerAssignment($ownForm, $ownStudent);
    createBannerAssignment($otherForm, $otherStudent);

    livewire(UserBanners::class)
        ->assertSee('1 item needs attention')
        ->assertSee('My Required Form')
        ->assertDontSee('Another Household Form');
});

it('identifies urgent payments first in the attention summary', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $form = createBannerForm('payment-test-form', 'Payment Test Form');
    createBannerAssignment($form, $student);

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 4500,
    ]);

    $component = livewire(UserBanners::class)
        ->assertSee('2 items need attention')
        ->assertSee('1 urgent payment · 1 form')
        ->assertSee('Urgent Payments')
        ->assertSee('Overdue payment')
        ->assertSee('$45.00')
        ->assertSee('Payment Test Form');

    $html = $component->html();

    expect(mb_strpos($html, 'Urgent Payments'))
        ->toBeLessThan(mb_strpos($html, 'Forms'));
});

it('includes currently held seats in the attention summary', function (): void {
    $hold = CourseHold::factory()->create([
        'user_id' => auth()->id(),
        'expires_at' => now()->addDay(),
    ]);
    CourseHoldSeat::factory()->create([
        'course_hold_id' => $hold->id,
    ]);

    livewire(UserBanners::class)
        ->assertSee('1 item needs attention')
        ->assertSee('1 held seat')
        ->assertSee('Held Seats')
        ->assertSee('Class seats held for you')
        ->assertSee('View held classes');
});
