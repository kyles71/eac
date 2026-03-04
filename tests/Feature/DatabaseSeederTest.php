<?php

declare(strict_types=1);

use App\Models\Calendar;
use App\Models\CartItem;
use App\Models\Costume;
use App\Models\Course;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\RestrictedCredit;
use App\Models\ShowcaseParticipation;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;

it('seeds the development database with all models', function (): void {
    $this->seed();

    expect(User::count())->toBeGreaterThanOrEqual(16)
        ->and(Student::count())->toBeGreaterThanOrEqual(15)
        ->and(Calendar::count())->toBe(2)
        ->and(Form::count())->toBe(2)
        ->and(Course::count())->toBeGreaterThanOrEqual(10)
        ->and(Product::count())->toBeGreaterThanOrEqual(20)
        ->and(Costume::count())->toBe(5)
        ->and(GiftCardType::count())->toBe(3)
        ->and(PaymentPlanTemplate::count())->toBe(3)
        ->and(DiscountCode::count())->toBe(4)
        ->and(Event::count())->toBeGreaterThanOrEqual(20)
        ->and(Enrollment::count())->toBeGreaterThanOrEqual(30)
        ->and(Order::count())->toBe(15)
        ->and(OrderItem::count())->toBeGreaterThanOrEqual(15)
        ->and(CartItem::count())->toBe(5)
        ->and(GiftCard::count())->toBe(6)
        ->and(PaymentPlan::count())->toBe(3)
        ->and(Installment::count())->toBeGreaterThanOrEqual(9)
        ->and(EventAttendee::count())->toBeGreaterThanOrEqual(20)
        ->and(RestrictedCredit::count())->toBe(3)
        ->and(StudentWaiver::count())->toBeGreaterThanOrEqual(1)
        ->and(ShowcaseParticipation::count())->toBeGreaterThanOrEqual(1)
        ->and(EmergencyContact::count())->toBeGreaterThanOrEqual(2)
        ->and(FormUser::count())->toBeGreaterThanOrEqual(2)
        ->and(CreditTransaction::count())->toBeGreaterThanOrEqual(5);
});
