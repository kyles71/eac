<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CreditTransactionType;
use App\Enums\FormTypes;
use App\Models\Calendar;
use App\Models\CartItem;
use App\Models\Costume;
use App\Models\Course;
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
use Database\Factories\CalendarFactory;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        CalendarFactory::new()->createMany([
            [
                'name' => 'My Calendar',
                'background_color' => null,
            ],
            [
                'name' => 'EAC Calendar',
                'background_color' => '#FF5733',
            ],
        ]);

        Form::factory()
            ->create(['name' => 'Student Waiver 25-26', 'form_type' => FormTypes::StudentWaiver->value]);

        if (config('app.env') !== 'production') {
            $this->seedDevData();
        }
    }

    private function seedDevData(): void
    {
        // ── Tier 0: Root entities ──

        $adminUser = User::firstOrCreate(
            ['email' => config('app.default_user.email')],
            [
                'first_name' => config('app.default_user.first_name'),
                'last_name' => config('app.default_user.last_name'),
                'password' => bcrypt(config('app.default_user.password')),
            ],
        );

        $users = User::factory(15)->create();
        $allUsers = $users->push($adminUser);

        $calendars = Calendar::all();

        $waiverForm = Form::first();
        $showcaseForm = Form::factory()->create([
            'name' => 'Showcase Participation 25-26',
            'form_type' => FormTypes::ShowcaseParticipation->value,
        ]);

        $giftCardTypes = collect([
            GiftCardType::factory()->denomination(2500)->create(),
            GiftCardType::factory()->denomination(5000)->create(),
            GiftCardType::factory()->denomination(10000)->create(),
        ]);

        $costumes = Costume::factory(5)->create();

        $planTemplates = collect([
            PaymentPlanTemplate::factory()->create(['name' => 'Monthly 3-Pay']),
            PaymentPlanTemplate::factory()->frequency(\App\Enums\PaymentPlanFrequency::Biweekly)->create(['name' => 'Biweekly 4-Pay', 'number_of_installments' => 4]),
            PaymentPlanTemplate::factory()->inactive()->create(['name' => 'Archived Plan']),
        ]);

        $discountCodes = collect([
            DiscountCode::factory()->percentage(15)->create(['code' => 'SAVE15']),
            DiscountCode::factory()->fixedAmount(2000)->create(['code' => 'FLAT20']),
            DiscountCode::factory()->expired()->create(['code' => 'EXPIRED10']),
            DiscountCode::factory()->exhausted()->create(['code' => 'USED5']),
        ]);

        // ── Tier 1: Depend on Tier 0 ──

        $students = Student::factory(15)->sequence(
            ...collect(range(0, 14))->map(fn (int $i) => ['user_id' => $allUsers->random()->id])->all()
        )->create();

        $courses = Course::factory(10)->sequence(
            ...collect(range(0, 9))->map(fn (int $i) => ['teacher_id' => $allUsers->random()->id])->all()
        )->create();

        $courseProducts = $courses->map(fn (Course $course) => Product::factory()->forCourse($course)->create());

        $giftCardProducts = $giftCardTypes->map(fn (GiftCardType $type) => Product::factory()->forGiftCardType($type)->create());

        $costumeProducts = $costumes->map(fn (Costume $costume) => Product::factory()->forCostume($costume)->create());

        $standaloneProducts = Product::factory(2)->standalone()->create();

        $allProducts = $courseProducts->merge($giftCardProducts)->merge($costumeProducts)->merge($standaloneProducts);

        // ── Tier 2: Depend on Tier 1 ──

        $events = collect();
        $courses->each(function (Course $course) use ($calendars, $events): void {
            $created = Event::factory(2)->create([
                'course_id' => $course->id,
                'calendar_id' => $calendars->random()->id,
            ]);
            $events->push(...$created);
        });

        $students->each(function (Student $student) use ($courses): void {
            $selectedCourses = $courses->random(fake()->numberBetween(2, 3));
            $selectedCourses->each(function (Course $course) use ($student): void {
                Enrollment::factory()->withStudent($student)->create([
                    'course_id' => $course->id,
                    'user_id' => $student->user_id,
                ]);
            });
        });

        // Attach forms to courses
        $waiverCourses = $courses->take(5);
        $showcaseCourses = $courses->skip(5)->take(5);
        $waiverCourses->each(fn (Course $course) => $course->forms()->attach($waiverForm->id));
        $showcaseCourses->each(fn (Course $course) => $course->forms()->attach($showcaseForm->id));

        // Orders — mix of statuses
        $completedOrders = Order::factory(10)->completed()->sequence(
            ...collect(range(0, 9))->map(fn () => ['user_id' => $allUsers->random()->id])->all()
        )->create();

        $pendingOrders = Order::factory(3)->sequence(
            ...collect(range(0, 2))->map(fn () => ['user_id' => $allUsers->random()->id])->all()
        )->create();

        $failedOrders = Order::factory(2)->failed()->sequence(
            ...collect(range(0, 1))->map(fn () => ['user_id' => $allUsers->random()->id])->all()
        )->create();

        // Apply discount codes to some completed orders
        $completedOrders->take(3)->each(function (Order $order, int $index) use ($discountCodes): void {
            $order->update([
                'discount_code_id' => $discountCodes[$index]->id,
                'discount_amount' => fake()->randomElement([500, 1000, 1500]),
            ]);
        });

        $allOrders = $completedOrders->merge($pendingOrders)->merge($failedOrders);

        // Cart items for a few users
        $allUsers->random(5)->each(function (User $user) use ($allProducts): void {
            CartItem::factory()->create([
                'user_id' => $user->id,
                'product_id' => $allProducts->random()->id,
            ]);
        });

        // Gift cards
        $activeGiftCards = collect();
        $giftCardTypes->each(function (GiftCardType $type) use ($allUsers, $activeGiftCards): void {
            $card = GiftCard::factory()->forType($type)->create([
                'purchased_by_user_id' => $allUsers->random()->id,
            ]);
            $activeGiftCards->push($card);
        });

        $redeemedGiftCards = GiftCard::factory(2)->redeemed()->create([
            'purchased_by_user_id' => $allUsers->random()->id,
        ]);

        GiftCard::factory()->inactive()->create([
            'purchased_by_user_id' => $allUsers->random()->id,
        ]);

        $allGiftCards = $activeGiftCards->merge($redeemedGiftCards);

        // ── Tier 3: Depend on Tier 2 ──

        // Order items
        $allOrders->each(function (Order $order) use ($allProducts): void {
            $itemCount = fake()->numberBetween(1, 3);
            $products = $allProducts->random($itemCount);

            $products->each(function (Product $product) use ($order): void {
                $state = $order->status === \App\Enums\OrderStatus::Completed ? 'fulfilled' : null;
                $factory = OrderItem::factory()->state([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'unit_price' => $product->price,
                    'total_price' => $product->price,
                    'quantity' => 1,
                ]);

                if ($state) {
                    $factory = $factory->fulfilled();
                }

                $factory->create();
            });
        });

        // Payment plans on some completed orders
        $ordersWithPlans = $completedOrders->random(3);
        $paymentPlans = $ordersWithPlans->map(function (Order $order) use ($planTemplates): PaymentPlan {
            $template = $planTemplates->whereNull('deleted_at')->random();

            return PaymentPlan::factory()->create([
                'order_id' => $order->id,
                'payment_plan_template_id' => $template->id,
                'total_amount' => $order->total,
                'number_of_installments' => $template->number_of_installments,
                'frequency' => $template->frequency,
            ]);
        });

        // Event attendees — mix of students and users
        $events->each(function (Event $event) use ($students, $allUsers): void {
            $attendeeCount = fake()->numberBetween(2, 5);

            collect(range(1, $attendeeCount))->each(function () use ($event, $students, $allUsers): void {
                if (fake()->boolean(70)) {
                    EventAttendee::factory()->forStudent($students->random())->create([
                        'event_id' => $event->id,
                    ]);
                } else {
                    EventAttendee::factory()->forUser($allUsers->random())->create([
                        'event_id' => $event->id,
                    ]);
                }
            });
        });

        // Restricted credits
        $allUsers->random(3)->each(function (User $user) use ($giftCardTypes, $activeGiftCards): void {
            RestrictedCredit::factory()->create([
                'user_id' => $user->id,
                'gift_card_type_id' => $giftCardTypes->random()->id,
                'gift_card_id' => $activeGiftCards->random()->id,
            ]);
        });

        // Student waivers — for students enrolled in waiver courses
        $waiverStudentIds = Enrollment::whereIn('course_id', $waiverCourses->pluck('id'))
            ->pluck('student_id')
            ->unique()
            ->filter();

        $studentWaivers = $waiverStudentIds->map(function (int $studentId) use ($waiverForm): StudentWaiver {
            $student = Student::find($studentId);
            $waiver = StudentWaiver::factory()->create();

            FormUser::factory()->create([
                'form_id' => $waiverForm->id,
                'user_id' => $student->user_id,
                'student_id' => $student->id,
                'responseable_type' => $waiver->getMorphClass(),
                'responseable_id' => $waiver->id,
            ]);

            return $waiver;
        });

        // Showcase participations — for students in showcase courses
        $showcaseStudentIds = Enrollment::whereIn('course_id', $showcaseCourses->pluck('id'))
            ->pluck('student_id')
            ->unique()
            ->filter();

        $showcaseParticipations = $showcaseStudentIds->map(function (int $studentId) use ($showcaseForm): ShowcaseParticipation {
            $student = Student::find($studentId);
            $participation = ShowcaseParticipation::factory()->create();

            FormUser::factory()->create([
                'form_id' => $showcaseForm->id,
                'user_id' => $student->user_id,
                'student_id' => $student->id,
                'responseable_type' => $participation->getMorphClass(),
                'responseable_id' => $participation->id,
            ]);

            return $participation;
        });

        // ── Tier 4: Depend on Tier 3 ──

        // Installments for each payment plan
        $paymentPlans->each(function (PaymentPlan $plan): void {
            $installmentAmount = (int) ($plan->total_amount / $plan->number_of_installments);

            collect(range(1, $plan->number_of_installments))->each(function (int $num) use ($plan, $installmentAmount): void {
                $factory = Installment::factory()->state([
                    'payment_plan_id' => $plan->id,
                    'installment_number' => $num,
                    'amount' => $installmentAmount,
                    'due_date' => now()->addMonths($num),
                ]);

                $factory = match (true) {
                    $num === 1 => $factory->paid(),
                    $num === 2 && fake()->boolean(50) => $factory->overdue(),
                    default => $factory,
                };

                $factory->create();
            });
        });

        // Emergency contacts for each student waiver
        $studentWaivers->each(function (StudentWaiver $waiver): void {
            EmergencyContact::factory(2)->create(['student_waiver_id' => $waiver->id]);
        });

        // Credit transactions via User::adjustCredit()
        $allUsers->random(5)->each(function (User $user) use ($allGiftCards): void {
            $user->adjustCredit(
                fake()->randomElement([2500, 5000, 10000]),
                CreditTransactionType::GiftCardRedemption,
                $allGiftCards->random(),
                'Gift card redeemed',
            );
        });

        $allUsers->random(3)->each(function (User $user): void {
            $user->adjustCredit(
                fake()->randomElement([1000, 2000, 5000]),
                CreditTransactionType::AdminAdjustment,
                description: 'Admin credit adjustment',
            );
        });

        // Discount code ↔ Product pivot
        $discountCodes->take(2)->each(function (DiscountCode $code) use ($allProducts): void {
            $code->products()->attach($allProducts->random(fake()->numberBetween(2, 4))->pluck('id'));
        });

        // Gift card type ↔ Product pivot
        $giftCardTypes->filter(fn (GiftCardType $type) => $type->restricted_to_product_type !== null)
            ->each(function (GiftCardType $type) use ($allProducts): void {
                $type->products()->attach($allProducts->random(2)->pluck('id'));
            });
    }
}
