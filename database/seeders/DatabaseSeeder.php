<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CreditTransactionType;
use App\Enums\FormTypes;
use App\Enums\ProductType;
use App\Models\Calendar;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\DashboardMessage;
use App\Models\DashboardQuickLink;
use App\Models\DiscountCode;
use App\Models\EmergencyContact;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Gear;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Holiday;
use App\Models\Installment;
use App\Models\LegalDocument;
use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\ManagedBanner;
use App\Models\ManagedBannerDismissal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Services\CreditLedgerService;
use App\Support\LegalDocuments\HealthSafetyPolicy;
use App\Support\LegalDocuments\TextMessageUpdatesPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Spatie\Tags\Tag;
use Symfony\Component\Finder\SplFileInfo;

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

        LegalDocument::query()->firstOrCreate(
            ['key' => TextMessageUpdatesPolicy::KEY],
            [
                'name' => 'Text Message Updates Policy',
                'description' => 'Policy for text message updates selected on student waivers.',
            ],
        );

        LegalDocument::query()->firstOrCreate(
            ['key' => HealthSafetyPolicy::KEY],
            [
                'name' => 'EAC Health & Safety Policy',
                'description' => 'Health and safety policy accepted on student waivers.',
            ],
        );

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

        $legalDocumentVersions = LegalDocument::query()
            ->orderBy('id')
            ->get()
            ->values()
            ->map(function (LegalDocument $legalDocument): LegalDocumentVersion {
                $existingVersion = $legalDocument->latestPublishedVersion()->first();

                if ($existingVersion instanceof LegalDocumentVersion) {
                    return $existingVersion;
                }

                return $legalDocument->publishVersion(
                    title: "{$legalDocument->name} v1",
                    content: '<p>Seeded legal document content.</p>',
                );
            });

        $giftCardTypes = collect([
            GiftCardType::factory()->denomination(2500)->create(),
            GiftCardType::factory()->denomination(5000)->create(),
            GiftCardType::factory()->denomination(10000)->create(),
            GiftCardType::factory()
                ->denomination(7500)
                ->customAmount(2500)
                ->restrictedToProductType(ProductType::Course)
                ->create(['name' => 'Flexible Class Gift Card']),
        ]);

        $gear = Gear::factory(5)->create();

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

        DashboardMessage::factory()->count(3)->create();
        DashboardQuickLink::factory()->count(3)->create();
        Holiday::factory()->count(2)->create();

        $managedBanners = collect([
            ManagedBanner::factory()->dismissible()->create(),
            ManagedBanner::factory()->create(),
            ManagedBanner::factory()->create([
                'published_at' => now()->addDay(),
            ]),
        ]);

        // ── Tier 1: Depend on Tier 0 ──

        $students = Student::factory(15)->sequence(
            ...collect(range(0, 14))->map(fn (int $i) => ['user_id' => $allUsers->random()->id])->all()
        )->create();

        $students->take(5)->each(fn (Student $student) => StudentEmail::factory(2)->create([
            'student_id' => $student->id,
        ]));

        $this->seedCompetitionData($students, $competitionStaff);

        $courses = Course::factory(10)->create();
        $courses->each(fn (Course $course): array => $course->teachers()->sync(
            $allUsers->random(fake()->numberBetween(1, 2))->pluck('id')->all()
        ));

        $courseProducts = $courses->map(fn (Course $course) => Product::factory()->forCourse($course)->create());

        $giftCardProducts = $giftCardTypes->map(fn (GiftCardType $type) => Product::factory()->forGiftCardType($type)->create());

        $gearProducts = $gear->map(fn (Gear $gear) => Product::factory()->forGear($gear)->create());

        $standaloneProducts = Product::factory(2)->standalone()->create();

        $allProducts = $courseProducts->merge($giftCardProducts)->merge($gearProducts)->merge($standaloneProducts);

        $this->seedProductImages($allProducts);

        $courseProducts
            ->take(2)
            ->values()
            ->each(function (Product $product, int $index) use ($allUsers): void {
                $window = ProductEarlyAccessWindow::factory()->create([
                    'product_id' => $product->id,
                    'available_from' => now()->subDays($index + 1),
                    'available_until' => now()->addDays(14 + $index),
                ]);

                $window->users()->attach($allUsers->take($index + 2)->pluck('id')->all());
            });

        $questionedProduct = $standaloneProducts->first();
        $productQuestions = collect([
            ProductQuestion::factory()->required()->create([
                'product_id' => $questionedProduct->id,
                'question' => 'Performer name for the program',
                'sort_order' => 1,
            ]),
            ProductQuestion::factory()->required()->select(['Small', 'Medium', 'Large'], true)->create([
                'product_id' => $questionedProduct->id,
                'question' => 'Preferred showcase shirt size',
                'sort_order' => 2,
            ]),
        ]);

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

        $customGiftCardProduct = $giftCardProducts->first(
            fn (Product $product): bool => $product->allowsCustomGiftCardAmount()
        );
        $customGiftCardOwner = $allUsers->first();

        CartItem::factory()->create([
            'user_id' => $customGiftCardOwner->id,
            'product_id' => $customGiftCardProduct->id,
            'custom_gift_card_amount' => 7500,
        ]);

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
                $customGiftCardAmount = $product->price === null
                    ? ($product->suggestedCustomGiftCardAmount() ?? 0)
                    : 0;
                $unitPrice = $customGiftCardAmount > 0
                    ? $customGiftCardAmount
                    : ($product->price ?? 0);
                $factory = OrderItem::factory()->state([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice,
                    'custom_gift_card_amount' => $customGiftCardAmount,
                    'quantity' => 1,
                ]);

                if ($state) {
                    $factory = $factory->fulfilled();
                }

                $factory->create();
            });
        });

        $seededStudent = $students->first();
        $seededCourse = $courses->first();
        $seededCourseProduct = $courseProducts->first(
            fn (Product $product): bool => $product->productable_id === $seededCourse->id
        );
        $scenarioOrderSubtotal = ($seededCourseProduct->price ?? 0)
            + ($questionedProduct->price ?? 0)
            + 7500;

        $scenarioOrder = Order::factory()->completed()->create([
            'user_id' => $seededStudent->user_id,
            'subtotal' => $scenarioOrderSubtotal,
            'total' => $scenarioOrderSubtotal,
        ]);

        $courseOrderItem = OrderItem::factory()->fulfilled()->create([
            'order_id' => $scenarioOrder->id,
            'product_id' => $seededCourseProduct->id,
            'unit_price' => $seededCourseProduct->price ?? 0,
            'total_price' => $seededCourseProduct->price ?? 0,
            'quantity' => 1,
        ]);

        Enrollment::factory()->withStudent($seededStudent)->create([
            'course_id' => $seededCourse->id,
            'user_id' => $seededStudent->user_id,
            'student_id' => $seededStudent->id,
            'order_item_id' => $courseOrderItem->id,
        ]);

        $questionOrderItem = OrderItem::factory()->fulfilled()->create([
            'order_id' => $scenarioOrder->id,
            'product_id' => $questionedProduct->id,
            'unit_price' => $questionedProduct->price ?? 0,
            'total_price' => $questionedProduct->price ?? 0,
            'quantity' => 1,
        ]);

        ProductQuestionAnswer::factory()->create([
            'order_item_id' => $questionOrderItem->id,
            'product_question_id' => $productQuestions[0]->id,
            'question' => $productQuestions[0]->question,
            'question_type' => $productQuestions[0]->type,
            'was_required' => $productQuestions[0]->is_required,
            'question_order' => $productQuestions[0]->sort_order,
            'answer' => 'Harper Quinn',
        ]);

        ProductQuestionAnswer::factory()->create([
            'order_item_id' => $questionOrderItem->id,
            'product_question_id' => $productQuestions[1]->id,
            'question' => $productQuestions[1]->question,
            'question_type' => $productQuestions[1]->type,
            'was_required' => $productQuestions[1]->is_required,
            'question_order' => $productQuestions[1]->sort_order,
            'selected_option' => 'Medium',
            'answer' => 'Medium',
        ]);

        OrderItem::factory()->fulfilled()->create([
            'order_id' => $scenarioOrder->id,
            'product_id' => $customGiftCardProduct->id,
            'unit_price' => 7500,
            'total_price' => 7500,
            'custom_gift_card_amount' => 7500,
            'quantity' => 1,
        ]);

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

        // Limited use credits
        $allUsers->random(3)->each(function (User $user) use ($giftCardTypes, $activeGiftCards): void {
            $giftCardType = $giftCardTypes->random();
            $giftCard = $activeGiftCards->random();

            app(CreditLedgerService::class)->issue(
                recipient: $user,
                amount: fake()->randomElement([2500, 5000, 10000]),
                description: 'Seeded limited use credit',
                restrictedToProductType: $giftCardType->restricted_to_product_type,
                source: $giftCard,
                transactionType: CreditTransactionType::GiftCardRedemption,
            );
        });

        $studentWaivers = FormUser::query()
            ->where('form_id', $waiverForm->id)
            ->with('responseable')
            ->get()
            ->map(fn (FormUser $formUser) => $formUser->responseable)
            ->filter(fn ($responseable): bool => $responseable instanceof StudentWaiver);

        $versionByKey = $legalDocumentVersions->keyBy(
            fn (LegalDocumentVersion $version): string => $version->document->key
        );

        LegalDocumentAcceptance::factory()->create([
            'legal_document_version_id' => $versionByKey['portal_terms']->id,
            'user_id' => $customGiftCardOwner->id,
            'acceptable_type' => $customGiftCardOwner->getMorphClass(),
            'acceptable_id' => $customGiftCardOwner->id,
        ]);

        LegalDocumentAcceptance::factory()->create([
            'legal_document_version_id' => $versionByKey['payment_plan_terms']->id,
            'user_id' => $scenarioOrder->user_id,
            'acceptable_type' => $scenarioOrder->getMorphClass(),
            'acceptable_id' => $scenarioOrder->id,
        ]);

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

        // Store credit grants
        $allUsers->random(5)->each(function (User $user) use ($allGiftCards): void {
            app(CreditLedgerService::class)->issue(
                recipient: $user,
                amount: fake()->randomElement([2500, 5000, 10000]),
                description: 'Gift card redeemed',
                source: $allGiftCards->random(),
                transactionType: CreditTransactionType::GiftCardRedemption,
            );
        });

        $issuer = $allUsers->first();
        $allUsers->random(3)->each(function (User $user) use ($issuer): void {
            app(CreditLedgerService::class)->issue(
                recipient: $user,
                amount: fake()->randomElement([1000, 2000, 5000]),
                description: 'Admin credit adjustment',
                issuer: $issuer,
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

        ManagedBannerDismissal::factory()->create([
            'managed_banner_id' => $managedBanners->first()->id,
            'user_id' => $customGiftCardOwner->id,
        ]);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function seedProductImages(Collection $products): void
    {
        $imageDirectory = database_path('seeders/assets/products');

        if (! File::isDirectory($imageDirectory)) {
            return;
        }

        $imagePaths = collect(File::files($imageDirectory))
            ->filter(fn (SplFileInfo $file): bool => in_array(
                mb_strtolower($file->getExtension()),
                ['gif', 'jpeg', 'jpg', 'png', 'webp'],
                true,
            ))
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->values();

        if ($imagePaths->isEmpty()) {
            return;
        }

        foreach ($products->shuffle()->values() as $index => $product) {
            $imageCount = min($index % 4, $imagePaths->count());

            if ($imageCount === 0) {
                continue;
            }

            foreach (fake()->randomElements($imagePaths->all(), $imageCount) as $imagePath) {
                $product
                    ->addMedia($imagePath)
                    ->preservingOriginal()
                    ->toMediaCollection('images');
            }
        }
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
