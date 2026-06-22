<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CreditTransactionType;
use App\Enums\FormTypes;
use App\Models\Calendar;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
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
use App\Models\LegalDocument;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\RestrictedCredit;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Spatie\Tags\Tag;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ShieldSeeder::class,
        ]);

        $this->seedSystemCalendars();

        Form::factory()
            ->create(['name' => 'Student Waiver 25-26', 'form_type' => FormTypes::StudentWaiver->value]);

        LegalDocument::query()->firstOrCreate(
            ['key' => 'payment_plan_terms'],
            [
                'name' => 'Payment Plan Terms & Conditions',
                'description' => 'Terms accepted before purchasing with a payment plan.',
            ],
        );

        LegalDocument::factory()->create([
            'key' => 'portal_terms',
            'name' => 'Portal Terms & Conditions',
            'description' => 'Terms accepted before creating an account.',
        ]);

        $adminUser = User::firstOrCreate(
            ['email' => config('app.default_user.email')],
            [
                'first_name' => config('app.default_user.first_name'),
                'last_name' => config('app.default_user.last_name'),
                'password' => bcrypt(config('app.default_user.password')),
            ],
        )
            ->assignRole('super_admin');

        if (config('app.env') !== 'production' && config('app.seed_demo_data')) {
            $this->seedDevData($adminUser);
        }

    }

    private function seedSystemCalendars(): void
    {
        foreach (Calendar::systemCalendarDefinitions() as $slug => $calendar) {
            Calendar::query()->updateOrCreate(
                ['slug' => $slug],
                $calendar,
            );
        }

        Tag::findOrCreate(Calendar::SLUG_EAC, Course::CALENDAR_TAG_TYPE);
        Tag::findOrCreate(Calendar::SLUG_COMP, Course::CALENDAR_TAG_TYPE);
    }

    private function seedDevData(User $adminUser): void
    {
        // ── Tier 0: Root entities ──

        $users = User::factory(15)->create();
        $competitionStaff = User::factory(3)->isTeacher()->sequence(
            [
                'first_name' => 'Jordan',
                'last_name' => 'Competition Director',
                'email' => 'competition.director@example.com',
            ],
            [
                'first_name' => 'Morgan',
                'last_name' => 'Mini Coach',
                'email' => 'mini.coach@example.com',
            ],
            [
                'first_name' => 'Riley',
                'last_name' => 'Senior Coach',
                'email' => 'senior.coach@example.com',
            ],
        )->create();
        $allUsers = $users->merge($competitionStaff)->push($adminUser);

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

        $this->seedCompetitionData($students, $competitionStaff);

        $courses = Course::factory(10)->create();
        $courses->each(fn (Course $course): array => $course->teachers()->sync(
            $allUsers->random(fake()->numberBetween(1, 2))->pluck('id')->all()
        ));

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
            $studentCount = min(fake()->numberBetween(1, $attendeeCount), $students->count());
            $userCount = min($attendeeCount - $studentCount, $allUsers->count());

            $students->random($studentCount)->each(fn (Student $student) => EventAttendee::factory()
                ->forStudent($student)
                ->create(['event_id' => $event->id]));

            $allUsers->random($userCount)->each(fn (User $user) => EventAttendee::factory()
                ->forUser($user)
                ->create(['event_id' => $event->id]));
        });

        // Restricted credits
        $allUsers->random(3)->each(function (User $user) use ($giftCardTypes, $activeGiftCards): void {
            RestrictedCredit::factory()->create([
                'user_id' => $user->id,
                'gift_card_type_id' => $giftCardTypes->random()->id,
                'gift_card_id' => $activeGiftCards->random()->id,
            ]);
        });

        $studentWaivers = FormUser::query()
            ->where('form_id', $waiverForm->id)
            ->with('responseable')
            ->get()
            ->map(fn (FormUser $formUser) => $formUser->responseable)
            ->filter(fn ($responseable): bool => $responseable instanceof StudentWaiver);

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

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, User>  $competitionStaff
     */
    private function seedCompetitionData(Collection $students, Collection $competitionStaff): void
    {
        $currentSeason = CompetitionSeason::factory()->current()->create([
            'name' => 'Current Competition Season',
        ]);
        $upcomingSeason = CompetitionSeason::query()->create([
            'name' => 'Upcoming Competition Season',
            'starts_on' => $currentSeason->ends_on->addDay()->toDateString(),
            'ends_on' => $currentSeason->ends_on->addYear()->toDateString(),
        ]);

        $miniTeam = CompetitionTeam::factory()->for($currentSeason, 'season')->create(['name' => 'Mini']);
        $juniorTeam = CompetitionTeam::factory()->for($currentSeason, 'season')->create(['name' => 'Junior']);
        $seniorTeam = CompetitionTeam::factory()->for($currentSeason, 'season')->create(['name' => 'Senior']);
        $upcomingTeam = CompetitionTeam::factory()->for($upcomingSeason, 'season')->create(['name' => 'Elite']);

        $miniTeam->students()->attach($students->take(6)->pluck('id')->all());
        $juniorTeam->students()->attach($students->slice(5, 6)->pluck('id')->all());
        $seniorTeam->students()->attach($students->take(-5)->pluck('id')->all());
        $upcomingTeam->students()->attach($students->take(3)->pluck('id')->all());

        $miniTeam->staff()->attach($competitionStaff->take(2)->pluck('id')->all());
        $juniorTeam->staff()->attach($competitionStaff->take(1)->pluck('id')->all());
        $seniorTeam->staff()->attach($competitionStaff->take(-2)->pluck('id')->all());
        $upcomingTeam->staff()->attach($competitionStaff->pluck('id')->all());
    }
}
