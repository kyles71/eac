<?php

declare(strict_types=1);

use App\Actions\RecurringPrivateLessons\BillRecurringPrivateLessonBillingPeriod;
use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson as CreateRecurringPrivateLessonAction;
use App\Actions\RecurringPrivateLessons\RemoveRecurringPrivateLessonCharge;
use App\Actions\RecurringPrivateLessons\RescheduleRecurringPrivateLessonCharge;
use App\Actions\Store\AddToCart;
use App\Actions\Store\CompleteOrder;
use App\Actions\Store\CreateOrder;
use App\Enums\CourseSemester;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\ScheduleFrequency;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Pages\CreateRecurringPrivateLesson;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Pages\ListRecurringPrivateLessons;
use App\Filament\Admin\Resources\RecurringPrivateLessons\Pages\ViewRecurringPrivateLesson;
use App\Filament\Admin\Resources\RecurringPrivateLessons\RelationManagers\ChargesRelationManager;
use App\Filament\Shared\Widgets\RecurringPrivateLessonAttention;
use App\Filament\User\Pages\Billing;
use App\Filament\User\Pages\BillingRecurringPrivateLessonsTable;
use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

it('lets an owner create a recurring private lesson from the admin resource', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00:29', 'America/New_York'));
    Filament::setCurrentPanel('admin');
    $owner = User::factory()->isOwner()->create();
    $this->actingAs($owner);
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();

    $createPage = livewire(CreateRecurringPrivateLesson::class);
    $formComponentKeys = array_keys($createPage->instance()->getSchema('form')->getFlatComponents());

    expect(array_search('repeat_through', $formComponentKeys, true))
        ->toBeLessThan(array_search('repeat_frequency', $formComponentKeys, true));

    $createPage
        ->assertSee('This description is visible to the dancer/parent.')
        ->assertSee('Every lesson must be scheduled more than 24 hours in advance.')
        ->assertSchemaComponentExists(
            'starts_at',
            checkComponentUsing: fn (DateTimePicker $component): bool => ! $component->hasSeconds()
                && CarbonImmutable::parse($component->getMinDate())->second === 0
                && CarbonImmutable::parse($component->getMinDate())->gt(now()->addDay()),
        )
        ->fillForm([
            'user_id' => $household->id,
            'student_id' => $student->id,
            'course_name' => 'Contemporary Private Lesson',
            'course_description' => 'Fall semester instruction',
            'semester' => CourseSemester::Fall->value,
            'teacher_ids' => [$teacher->id],
            'lesson_price_dollars' => '65.00',
            'status' => 'Active',
            'starts_at' => '2026-08-10 17:00:00',
            'duration_minutes' => 60,
            'repeat_frequency' => ScheduleFrequency::Biweekly->value,
            'repeat_through' => '2026-09-21',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $series = RecurringPrivateLesson::query()->sole();
    $charges = $series->charges()->with('event')->get()->sortBy('event.start_time')->values();
    $charges->first()->update(['status' => RecurringPrivateLessonChargeStatus::Billed]);

    assertDatabaseHas(RecurringPrivateLesson::class, [
        'id' => $series->id,
        'user_id' => $household->id,
        'student_id' => $student->id,
        'lesson_price' => 6500,
    ]);

    livewire(ListRecurringPrivateLessons::class)
        ->loadTable()
        ->assertTableColumnExists('next_unbilled')
        ->assertTableColumnStateSet(
            'next_unbilled',
            $charges->get(1)->event->start_time,
            $series,
        )
        ->assertCanSeeTableRecords([$series]);
    livewire(ViewRecurringPrivateLesson::class, ['record' => $series->id])
        ->assertOk()
        ->assertSee('Contemporary Private Lesson');
});

it('populates the recurring private lesson household when its dancer is selected first', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->isOwner()->create());
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();

    livewire(CreateRecurringPrivateLesson::class)
        ->set('data.student_id', $student->id)
        ->assertSchemaStateSet(['user_id' => $household->id]);
});

it('shows the recurring lesson table and payment policy in the household billing page', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $owner = User::factory()->isOwner()->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();
    $series = app(CreateRecurringPrivateLessonAction::class)->handle(
        $household,
        $student,
        [$teacher->id],
        'Ballet Private Lesson',
        null,
        CourseSemester::Fall,
        6000,
        CarbonImmutable::parse('2026-08-10 17:00', 'America/New_York'),
        60,
        CarbonImmutable::parse('2026-08-24', 'America/New_York'),
        ScheduleFrequency::Weekly,
    );
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($series->billingPeriods->first(), $owner);
    $this->actingAs($household);
    Filament::setCurrentPanel('user');

    livewire(Billing::class)
        ->assertOk()
        ->assertSee('Recurring Private Lessons')
        ->assertDontSee('Payment is required 24 hours before each lesson')
        ->assertSchemaComponentExists(
            'recurring_private_lesson_payment_policy_icon',
            'content',
            fn (Icon $icon): bool => $icon->getIcon() === Heroicon::OutlinedExclamationTriangle
                && $icon->getColor() === 'warning'
                && $icon->getSize() === IconSize::Large,
        )
        ->assertSee('must be paid at least 24 hours before the lesson starts')
        ->assertSee('Ballet Private Lesson')
        ->assertSee('Pay All Billed Lessons')
        ->assertSee('$60.00');
});

it('isolates, filters, and orders the household recurring lesson table', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $owner = User::factory()->isOwner()->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();
    $series = filamentBillingSeries($household, $student, $teacher, '2026-08-10', '2026-08-24');
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($series->billingPeriods->first(), $owner);
    $charges = $series->charges()->with('event')->get()->sortBy('event.start_time')->values();
    $charges->get(0)->update(['status' => RecurringPrivateLessonChargeStatus::Paid]);
    $charges->get(1)->update(['status' => RecurringPrivateLessonChargeStatus::Cancelled]);

    $otherHousehold = User::factory()->create();
    $otherStudent = Student::factory()->for($otherHousehold)->create();
    $otherCharge = filamentBillingSeries($otherHousehold, $otherStudent, $teacher, '2026-08-24', '2026-08-24')
        ->charges
        ->sole();

    $this->travelTo(CarbonImmutable::parse('2026-08-18 09:00', 'America/New_York'));
    $this->actingAs($household);
    Filament::setCurrentPanel('user');

    $table = livewire(BillingRecurringPrivateLessonsTable::class);

    expect($table->instance()->getTableFilterState('status'))->toBe(['values' => []]);

    $table
        ->assertCanSeeTableRecords([
            $charges->get(2),
            $charges->get(1),
            $charges->get(0),
        ], true)
        ->assertCanNotSeeTableRecords([$otherCharge])
        ->filterTable('status', [RecurringPrivateLessonChargeStatus::Billed->value])
        ->assertCanSeeTableRecords([$charges->get(2)])
        ->assertCanNotSeeTableRecords([$charges->get(0), $charges->get(1)]);
});

it('adds one or all currently payable billed lessons to the cart', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $owner = User::factory()->isOwner()->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();
    $series = filamentBillingSeries($household, $student, $teacher, '2026-08-10', '2026-08-24');
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($series->billingPeriods->first(), $owner);
    $charges = $series->charges()->with(['event', 'product'])->get()->sortBy('event.start_time')->values();
    $this->actingAs($household);
    Filament::setCurrentPanel('user');

    livewire(BillingRecurringPrivateLessonsTable::class)
        ->callAction(TestAction::make('pay')->table($charges->first()));

    assertDatabaseHas('cart_items', [
        'user_id' => $household->id,
        'product_id' => $charges->first()->product->id,
    ]);

    livewire(BillingRecurringPrivateLessonsTable::class)
        ->callAction(TestAction::make('payAllBilledLessons')->table());

    assertDatabaseCount('cart_items', 3);
});

it('shows lesson management actions only to owners and removes the waiver action', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $owner = User::factory()->isOwner()->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();
    $series = filamentBillingSeries($household, $student, $teacher, '2026-08-10', '2026-08-10');
    $charge = $series->charges->sole();
    Filament::setCurrentPanel('admin');
    $this->actingAs($owner);

    livewire(ChargesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewRecurringPrivateLesson::class,
    ])
        ->assertActionVisible(TestAction::make('billMonth')->table($charge))
        ->assertActionVisible(TestAction::make('reschedule')->table($charge))
        ->assertActionVisible(TestAction::make('removeLesson')->table($charge))
        ->assertActionDoesNotExist(TestAction::make('sendMonth')->table($charge))
        ->assertActionDoesNotExist(TestAction::make('waive')->table($charge))
        ->assertActionDoesNotExist(TestAction::make('transferCoverage')->table($charge))
        ->assertActionDoesNotExist(TestAction::make('issueCredit')->table($charge))
        ->assertActionDoesNotExist(TestAction::make('refund')->table($charge));

    livewire(ChargesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewRecurringPrivateLesson::class,
    ])
        ->loadTable()
        ->assertActionVisible(TestAction::make('reschedule')->table($charge))
        ->mountAction(TestAction::make('reschedule')->table($charge))
        ->assertActionMounted(TestAction::make('reschedule')->table($charge))
        ->assertSchemaComponentExists(
            'reason',
            'mountedActionSchema0',
            function (Textarea $textarea): bool {
                $helper = $textarea->getChildSchema(
                    Textarea::BELOW_CONTENT_SCHEMA_KEY,
                )?->getComponents()[0] ?? null;

                return $helper instanceof Text
                    && $helper->getContent() === 'This reason is visible to the dancer/parent.';
            },
        )
        ->unmountAction()
        ->assertActionVisible(TestAction::make('removeLesson')->table($charge))
        ->mountAction(TestAction::make('removeLesson')->table($charge))
        ->assertActionMounted(TestAction::make('removeLesson')->table($charge))
        ->assertSchemaComponentExists(
            'reason',
            'mountedActionSchema0',
            function (Textarea $textarea): bool {
                $helper = $textarea->getChildSchema(
                    Textarea::BELOW_CONTENT_SCHEMA_KEY,
                )?->getComponents()[0] ?? null;

                return $helper instanceof Text
                    && $helper->getContent() === 'This reason is visible to the dancer/parent.';
            },
        )
        ->unmountAction();

    livewire(ChargesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewRecurringPrivateLesson::class,
    ])
        ->assertActionExists(
            TestAction::make('removeLesson')->table($charge),
            checkActionUsing: fn (Action $action): bool => $action->getModalDescription()
                === 'This permanently removes the scheduled lesson. The family has not been asked to pay for it.',
        );

    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($charge->billingPeriod, $owner);
    app(AddToCart::class)->handle($household, $charge->product);
    app(CompleteOrder::class)->handle(app(CreateOrder::class)->handle($household));
    $charge->refresh();

    livewire(ChargesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewRecurringPrivateLesson::class,
    ])
        ->assertActionExists(
            TestAction::make('reschedule')->table($charge),
            checkActionUsing: fn (Action $action): bool => $action->getModalDescription()
                === 'The existing payment stays attached to the rescheduled lesson.',
        );

    livewire(ChargesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewRecurringPrivateLesson::class,
    ])
        ->mountAction(TestAction::make('removeLesson')->table($charge))
        ->assertSchemaComponentExists(
            'payment_resolution',
            'mountedActionSchema0',
            fn (Select $select): bool => ! $select->isSearchable(),
        )
        ->unmountAction()
        ->callAction(
            TestAction::make('removeLesson')->table($charge),
            ['reason' => 'Family cannot attend'],
        )
        ->assertHasActionErrors(['payment_resolution' => 'required']);

    $this->actingAs($teacher);

    livewire(ChargesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewRecurringPrivateLesson::class,
    ])
        ->assertActionHidden(TestAction::make('reschedule')->table($charge))
        ->assertActionHidden(TestAction::make('removeLesson')->table($charge));
});

it('shows reschedule and status notes as lesson table tooltips', function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $owner = User::factory()->isOwner()->create();
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $teacher = User::factory()->isTeacher()->create();
    $series = filamentBillingSeries($household, $student, $teacher, '2026-08-10', '2026-08-17');
    $charges = $series->charges()->with('event')->get()->sortBy('event.start_time')->values();
    $charge = $charges->first();
    $chargeWithoutNotes = $charges->last();

    app(RescheduleRecurringPrivateLessonCharge::class)->handle(
        $charge,
        CarbonImmutable::parse('2026-08-12 18:00', 'America/New_York'),
        $owner,
        'Teacher moved the lesson',
    );
    app(BillRecurringPrivateLessonBillingPeriod::class)->handle($charge->refresh()->billingPeriod, $owner);
    app(RemoveRecurringPrivateLessonCharge::class)->handle(
        $charge,
        $owner,
        'Studio closed unexpectedly',
    );

    Filament::setCurrentPanel('admin');
    $this->actingAs($owner);
    $charge->refresh();

    livewire(ChargesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewRecurringPrivateLesson::class,
    ])
        ->assertTableColumnExists(
            'event.start_time',
            function (TextColumn $column): bool {
                $icon = $column->getIcon($column->getState());

                return $icon instanceof Icon
                    && $icon->getTooltip() === 'Teacher moved the lesson'
                    && $icon->getColor() === 'warning'
                    && $icon->getSize() === IconSize::Small
                    && $column->getIconPosition() === IconPosition::After
                    && $column->getTooltip() === null;
            },
            $charge,
        )
        ->assertTableColumnExists(
            'status',
            function (TextColumn $column): bool {
                $icon = $column->getIcon($column->getState());

                return $icon instanceof Icon
                    && $icon->getTooltip() === 'Studio closed unexpectedly'
                    && $icon->getColor() === 'warning'
                    && $icon->getSize() === IconSize::Small
                    && $column->getIconPosition() === IconPosition::After
                    && $column->getTooltip() === null;
            },
            $charge,
        )
        ->assertTableColumnExists(
            'event.start_time',
            fn (TextColumn $column): bool => $column->getIcon($column->getState()) === null,
            $chargeWithoutNotes,
        )
        ->assertTableColumnExists(
            'status',
            fn (TextColumn $column): bool => $column->getIcon($column->getState()) === null,
            $chargeWithoutNotes,
        );
});

it('shows the owner billing card with total and soonest-month scheduled lesson counts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();
    $series = RecurringPrivateLesson::factory()->create();

    foreach ([
        '2026-08-01' => 4,
        '2026-09-01' => 2,
        '2026-10-01' => 2,
        '2026-12-01' => 2,
    ] as $periodStart => $count) {
        $period = RecurringPrivateLessonBillingPeriod::factory()
            ->for($series)
            ->create(['period_start' => $periodStart]);
        RecurringPrivateLessonCharge::factory()
            ->count($count)
            ->create([
                'recurring_private_lesson_id' => $series->id,
                'recurring_private_lesson_billing_period_id' => $period->id,
            ]);
    }

    Filament::setCurrentPanel('admin');
    $this->actingAs($owner);

    expect(RecurringPrivateLessonAttention::canView())->toBeTrue();
    livewire(RecurringPrivateLessonAttention::class)
        ->assertSee('10 total')
        ->assertSee('4 in August');

    $this->actingAs($teacher);
    expect(RecurringPrivateLessonAttention::canView())->toBeFalse();
});

function filamentBillingSeries(
    User $household,
    Student $student,
    User $teacher,
    string $startsOn,
    string $endsOn,
): RecurringPrivateLesson {
    return app(CreateRecurringPrivateLessonAction::class)->handle(
        $household,
        $student,
        [$teacher->id],
        'Ballet Private Lesson',
        null,
        CourseSemester::Fall,
        6000,
        CarbonImmutable::parse("{$startsOn} 17:00", 'America/New_York'),
        60,
        CarbonImmutable::parse($endsOn, 'America/New_York'),
        ScheduleFrequency::Weekly,
    );
}
