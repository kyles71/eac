<?php

declare(strict_types=1);

use App\Actions\Events\ManageEventSubstitution;
use App\Enums\EventSubstituteRequestReason;
use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\ReportCategory;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportKey;
use App\Enums\ReportWidgetKey;
use App\Filament\Admin\Pages\Reports\FinanceReports;
use App\Filament\Admin\Pages\Reports\PayrollReport;
use App\Filament\Admin\Pages\Reports\SickLeaveReport;
use App\Filament\Admin\Widgets\Reports\FinanceOverview;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CreditGrant;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventSubstituteCoverage;
use App\Models\EventSubstituteRequest;
use App\Models\GiftCard;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundPayment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\FinanceReportService;
use App\Services\Reports\ReportExportService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Storage::fake('local');
});

it('grants finance reports and its widget to owners but not teachers', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();

    expect(ReportCategory::Finance->canView($owner))->toBeTrue()
        ->and(ReportKey::Payroll->canView($owner))->toBeTrue()
        ->and(ReportKey::SickLeave->canView($owner))->toBeTrue()
        ->and(ReportWidgetKey::FinanceOverview->canView($owner))->toBeTrue()
        ->and(ReportCategory::Finance->canView($teacher))->toBeFalse()
        ->and(ReportKey::Payroll->canView($teacher))->toBeFalse()
        ->and(ReportKey::SickLeave->canView($teacher))->toBeFalse()
        ->and(ReportWidgetKey::FinanceOverview->canView($teacher))->toBeFalse();

    $this->actingAs($teacher);
    $this->get(FinanceReports::getUrl(panel: 'admin'))->assertForbidden();
    $this->get(PayrollReport::getUrl(panel: 'admin'))->assertForbidden();
    $this->get(SickLeaveReport::getUrl(panel: 'admin'))->assertForbidden();
});

it('allows finance widget permission without exposing either finance report', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(ReportWidgetKey::FinanceOverview->permission());
    $this->actingAs($user);

    expect(ReportCategory::Finance->canView($user))->toBeTrue()
        ->and(FinanceReports::canAccess())->toBeTrue()
        ->and(FinanceOverview::canView())->toBeTrue()
        ->and(ReportKey::Payroll->canView($user))->toBeFalse()
        ->and(ReportKey::SickLeave->canView($user))->toBeFalse();

    livewire(FinanceReports::class)
        ->assertOk()
        ->assertDontSee('Payroll Report')
        ->assertDontSee('Sick Leave Report');

    livewire(FinanceOverview::class)
        ->assertOk()
        ->assertSee('Payment-plan income is recognized as installments are paid')
        ->assertSee('Pending Payment Plan Income')
        ->assertSee('payment-plan fees excluded');
});

it('renders the term selector before the finance widget and lists the reports', function (): void {
    $this->actingAs(User::factory()->isOwner()->create());

    livewire(FinanceReports::class)
        ->assertOk()
        ->assertSee('Payroll Report')
        ->assertSee('Sick Leave Report')
        ->assertSeeInOrder([
            'Dashboard Academic Term',
            'Payroll Report',
        ]);

    livewire(FinanceOverview::class)
        ->assertOk()
        ->assertSee('Gross Enrollments')
        ->assertSee('Net Enrollment Purchases')
        ->assertSee('Pending Payment Plan Income');

    expect(app(FinanceReportService::class)->dashboard(null))->toBe([
        'gross_enrollments' => 0,
        'net_enrollment_purchases' => 0,
        'pending_payment_plan_income' => 0,
    ]);
});

it('calculates collected gross and net course purchases by academic term', function (): void {
    $term = AcademicTerm::factory()->create();
    $otherTerm = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create();
    $otherCourse = Course::factory()->for($otherTerm)->create();
    $product = Product::factory()->forCourse($course)->create();
    $otherProduct = Product::factory()->forCourse($otherCourse)->create();
    $order = Order::factory()->completed()->create([
        'subtotal' => 15000,
        'total' => 13000,
        'discount_amount' => 1000,
        'restricted_credit_applied' => 500,
        'credit_applied' => 500,
        'payment_plan_fee' => 900,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($product)->create([
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
        'discount_allocated' => 1000,
        'restricted_credit_allocated' => 500,
        'credit_allocated' => 500,
        'stripe_allocated' => 8000,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($otherProduct)->create([
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
        'stripe_allocated' => 5000,
    ]);
    $refundedOrder = Order::factory()->create([
        'status' => OrderStatus::Refunded,
        'subtotal' => 4000,
        'total' => 4000,
    ]);
    OrderItem::factory()->fulfilled()->for($refundedOrder)->for($product)->create([
        'quantity' => 1,
        'unit_price' => 4000,
        'total_price' => 4000,
        'stripe_allocated' => 4000,
    ]);
    $refund = OrderRefund::factory()->for($refundedOrder)->create([
        'amount' => 4000,
    ]);
    OrderRefundPayment::factory()->for($refund)->create([
        'amount' => 4000,
        'status' => OrderRefundPaymentStatus::Succeeded,
    ]);
    CreditGrant::factory()->amount(1000)->create([
        'created_at' => $term->starts_on->addMonth(),
    ]);
    $failedOrder = Order::factory()->failed()->create([
        'subtotal' => 3000,
        'total' => 3000,
    ]);
    OrderItem::factory()->fulfilled()->for($failedOrder)->for($product)->create([
        'quantity' => 1,
        'unit_price' => 3000,
        'total_price' => 3000,
    ]);

    expect(app(FinanceReportService::class)->dashboard($term))->toBe([
        'gross_enrollments' => 14000,
        'net_enrollment_purchases' => 8000,
        'pending_payment_plan_income' => 0,
    ]);
});

it('reconstructs missing legacy discount allocations', function (): void {
    $term = AcademicTerm::factory()->create();
    $otherTerm = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create();
    $otherCourse = Course::factory()->for($otherTerm)->create();
    $product = Product::factory()->forCourse($course)->create();
    $otherProduct = Product::factory()->forCourse($otherCourse)->create();
    $order = Order::factory()->completed()->create([
        'subtotal' => 20000,
        'total' => 14000,
        'discount_amount' => 2000,
        'credit_applied' => 4000,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($product)->create([
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($otherProduct)->create([
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
    ]);

    expect(app(FinanceReportService::class)->dashboard($term))->toBe([
        'gross_enrollments' => 10000,
        'net_enrollment_purchases' => 9000,
        'pending_payment_plan_income' => 0,
    ]);
});

it('recognizes paid payment plan installments and eligible term credit while excluding fees', function (): void {
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create();
    $product = Product::factory()->forCourse($course)->create(['price' => 10000]);
    $template = PaymentPlanTemplate::factory()
        ->forProductType(ProductType::Course)
        ->create(['number_of_installments' => 4]);
    $order = Order::factory()->completed()->create([
        'subtotal' => 10000,
        'total' => 8240,
        'discount_amount' => 2000,
        'payment_plan_fee' => 240,
        'payment_plan_principal' => 8000,
        'payment_plan_subtotal' => 10000,
        'payment_plan_discount_amount' => 2000,
        'payment_plan_template_id' => $template->id,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($product)->create([
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
        'discount_allocated' => 2000,
        'stripe_allocated' => 8000,
    ]);
    $paymentPlan = PaymentPlan::factory()->for($order)->create([
        'payment_plan_template_id' => $template->id,
        'total_amount' => 8240,
        'number_of_installments' => 4,
    ]);
    Installment::factory()->paid()->for($paymentPlan)->create([
        'installment_number' => 1,
        'amount' => 2060,
    ]);
    $futureInstallments = Installment::factory(3)->for($paymentPlan)->sequence(
        ['installment_number' => 2],
        ['installment_number' => 3],
        ['installment_number' => 4],
    )->create(['amount' => 2060]);
    $refund = OrderRefund::factory()->for($order)->create(['amount' => 1530]);
    OrderRefundPayment::factory()->for($refund)->create([
        'amount' => 1030,
        'status' => OrderRefundPaymentStatus::Succeeded,
    ]);
    OrderRefundPayment::factory()->for($refund)->create([
        'stripe_payment_intent_id' => 'pi_pending_refund',
        'amount' => 500,
        'status' => OrderRefundPaymentStatus::Pending,
    ]);
    CreditGrant::factory()->amount(500)->create([
        'created_at' => $term->starts_on->addMonth(),
    ]);
    CreditGrant::factory()->amount(600)->expired()->create([
        'created_at' => $term->starts_on->addMonth(),
    ]);
    CreditGrant::factory()->amount(700)->create([
        'created_at' => $term->starts_on->addMonth(),
        'revoked_at' => now(),
    ]);
    $giftCard = GiftCard::factory()->create();
    CreditGrant::factory()->amount(800)->create([
        'source_type' => $giftCard->getMorphClass(),
        'source_id' => $giftCard->id,
        'created_at' => $term->starts_on->addMonth(),
    ]);
    CreditGrant::factory()->amount(900)->create([
        'created_at' => $term->starts_on->subDay(),
    ]);

    expect(app(FinanceReportService::class)->dashboard($term))->toBe([
        'gross_enrollments' => 2500,
        'net_enrollment_purchases' => 500,
        'pending_payment_plan_income' => 6000,
    ]);

    $futureInstallments->first()->update([
        'status' => InstallmentStatus::Paid,
        'paid_at' => now(),
    ]);

    expect(app(FinanceReportService::class)->dashboard($term))->toBe([
        'gross_enrollments' => 5000,
        'net_enrollment_purchases' => 2500,
        'pending_payment_plan_income' => 4000,
    ]);

    $futureInstallments->each->update([
        'status' => InstallmentStatus::Paid,
        'paid_at' => now(),
    ]);

    expect(app(FinanceReportService::class)->dashboard($term)['pending_payment_plan_income'])->toBe(0);
});

it('allocates all collectible installment states by course term and honors refund cancellation', function (): void {
    $term = AcademicTerm::factory()->create();
    $otherTerm = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create();
    $otherCourse = Course::factory()->for($otherTerm)->create();
    $product = Product::factory()->forCourse($course)->create(['price' => 10000]);
    $otherProduct = Product::factory()->forCourse($otherCourse)->create(['price' => 4000]);
    $standaloneProduct = Product::factory()->create(['price' => 6000]);
    $template = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Any,
        'min_price' => 0,
        'max_price' => 50000,
        'number_of_installments' => 5,
    ]);
    $order = Order::factory()->completed()->create([
        'subtotal' => 20000,
        'total' => 18540,
        'discount_amount' => 1000,
        'credit_applied' => 1000,
        'payment_plan_fee' => 540,
        'payment_plan_principal' => 18000,
        'payment_plan_subtotal' => 20000,
        'payment_plan_discount_amount' => 1000,
        'payment_plan_credit_applied' => 1000,
        'payment_plan_template_id' => $template->id,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($product)->create([
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
        'discount_allocated' => 1000,
        'credit_allocated' => 1000,
        'stripe_allocated' => 8000,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($otherProduct)->create([
        'quantity' => 1,
        'unit_price' => 4000,
        'total_price' => 4000,
        'stripe_allocated' => 4000,
    ]);
    OrderItem::factory()->fulfilled()->for($order)->for($standaloneProduct)->create([
        'quantity' => 1,
        'unit_price' => 6000,
        'total_price' => 6000,
        'stripe_allocated' => 6000,
    ]);
    $paymentPlan = PaymentPlan::factory()->for($order)->create([
        'payment_plan_template_id' => $template->id,
        'total_amount' => 18540,
        'number_of_installments' => 5,
    ]);
    Installment::factory()->paid()->for($paymentPlan)->create([
        'installment_number' => 1,
        'amount' => 3708,
    ]);
    Installment::factory()->for($paymentPlan)->create([
        'installment_number' => 2,
        'amount' => 3708,
        'due_date' => $term->ends_on->addMonth(),
        'status' => InstallmentStatus::Pending,
    ]);
    Installment::factory()->failed()->for($paymentPlan)->create([
        'installment_number' => 3,
        'amount' => 3708,
    ]);
    Installment::factory()->overdue()->for($paymentPlan)->create([
        'installment_number' => 4,
        'amount' => 3708,
    ]);
    Installment::factory()->for($paymentPlan)->create([
        'installment_number' => 5,
        'amount' => 3708,
        'status' => InstallmentStatus::Cancelled,
    ]);
    $service = app(FinanceReportService::class);

    expect($service->dashboard($term)['pending_payment_plan_income'])->toBe(4800)
        ->and($service->dashboard($otherTerm)['pending_payment_plan_income'])->toBe(2400);

    $cancellation = OrderRefund::factory()->for($order)->create([
        'cancel_remaining_installments' => true,
        'status' => OrderRefundStatus::Processing,
    ]);

    expect($service->dashboard($term)['pending_payment_plan_income'])->toBe(0);

    $cancellation->update(['status' => OrderRefundStatus::Failed]);

    expect($service->dashboard($term)['pending_payment_plan_income'])->toBe(4800);

    $cancellation->update(['status' => OrderRefundStatus::Succeeded]);

    expect($service->dashboard($term)['pending_payment_plan_income'])->toBe(0);
});

it('includes past and future payroll events in range while always excluding cancellations', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create(['name' => 'Payroll Jazz']);
    $assigned = User::factory()->isTeacher()->create([
        'first_name' => 'Alex',
        'last_name' => 'Assigned',
    ]);
    $coTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Bailey',
        'last_name' => 'CoTeacher',
    ]);
    $substitute = User::factory()->isTeacher()->create([
        'first_name' => 'Sam',
        'last_name' => 'Substitute',
    ]);
    $course->teachers()->sync([$assigned->id, $coTeacher->id]);
    Enrollment::factory(2)->for($course)->create();
    $past = Event::factory()->for($course)->create([
        'start_time' => '2040-09-03 14:00:00',
        'end_time' => '2040-09-03 15:30:00',
        'substitute_teacher_id' => $substitute->id,
    ]);
    EventSubstituteRequest::factory()->accepted()->for($past)->create([
        'teacher_id' => $substitute->id,
        'requested_by_user_id' => $owner->id,
        'request_reason' => 'Instructor unavailable',
    ]);
    Event::factory()->for($course)->create([
        'start_time' => '2040-09-10 14:00:00',
        'end_time' => '2040-09-10 15:00:00',
    ]);
    Event::factory()->standalone()->create([
        'name' => 'Standalone Workshop',
        'start_time' => '2040-09-12 14:00:00',
        'end_time' => '2040-09-12 15:00:00',
    ]);
    Event::factory()->for($course)->create([
        'start_time' => '2040-09-05 14:00:00',
        'end_time' => '2040-09-05 15:00:00',
        'cancelled_at' => '2040-09-01 12:00:00',
    ]);
    Event::factory()->for($course)->create([
        'start_time' => '2040-10-01 14:00:00',
        'end_time' => '2040-10-01 15:00:00',
    ]);
    $dataset = app(FinanceReportService::class)->dataset(ReportKey::Payroll, $owner, [
        'date_range' => [
            'from' => '2040-09-01',
            'through' => '2040-09-30',
        ],
    ]);

    expect($dataset->rows)->toHaveCount(5)
        ->and($dataset->rows[0])->toMatchArray([
            'course_name' => 'Payroll Jazz',
            'enrollment_count' => 2,
            'assigned_instructors' => 'Alex Assigned',
            'sub_instructor' => 'Sam Substitute',
            'sub_reason' => 'Instructor unavailable',
            'hours' => 1.5,
        ])
        ->and($dataset->rows[1])->toMatchArray([
            'course_name' => 'Payroll Jazz',
            'assigned_instructors' => 'Bailey CoTeacher',
            'hours' => 1.5,
        ])
        ->and(collect($dataset->rows)->firstWhere('course_name', 'Standalone Workshop'))->toMatchArray([
            'enrollment_count' => 0,
            'assigned_instructors' => 'Unassigned',
            'sub_instructor' => '—',
            'hours' => 1.0,
        ]);
});

it('persists sick attribution and surfaces ambiguous requests as unreconciled', function (): void {
    $owner = User::factory()->isOwner()->create([
        'first_name' => 'Olivia',
        'last_name' => 'Owner',
    ]);
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create(['name' => 'Solo Ballet']);
    $instructor = User::factory()->isTeacher()->create([
        'first_name' => 'Taylor',
        'last_name' => 'Teacher',
    ]);
    $substitute = User::factory()->isTeacher()->create();
    $course->teachers()->sync([$instructor->id]);
    Enrollment::factory()->for($course)->create();
    $event = Event::factory()->for($course)->create([
        'start_time' => '2040-09-07 14:00:00',
        'end_time' => '2040-09-07 15:00:00',
    ]);
    $request = app(ManageEventSubstitution::class)->requestSubstitute(
        $event,
        $substitute,
        $owner,
        'Sick',
        EventSubstituteRequestReason::Sick,
    );

    expect($request->reason_type)->toBe(EventSubstituteRequestReason::Sick)
        ->and($request->sick_instructor_id)->toBe($instructor->id);

    $ambiguousCourse = Course::factory()->for($term)->create(['name' => 'Co-taught Tap']);
    $ambiguousCourse->teachers()->sync([
        User::factory()->isTeacher()->create()->id,
        User::factory()->isTeacher()->create()->id,
    ]);
    $ambiguousEvent = Event::factory()->for($ambiguousCourse)->create([
        'start_time' => '2040-09-08 14:00:00',
        'end_time' => '2040-09-08 15:00:00',
    ]);
    $ambiguousCoverage = EventSubstituteCoverage::factory()->for($ambiguousEvent)->create([
        'covered_teacher_id' => null,
    ]);
    EventSubstituteRequest::factory()
        ->for($ambiguousEvent)
        ->for($ambiguousCoverage, 'coverage')
        ->create([
            'teacher_id' => User::factory()->isTeacher(),
            'requested_by_user_id' => $owner->id,
            'reason_type' => EventSubstituteRequestReason::Sick,
            'request_reason' => 'Sick',
            'sick_instructor_id' => null,
        ]);
    $cancelledEvent = Event::factory()->for($course)->create([
        'start_time' => '2040-09-09 14:00:00',
        'end_time' => '2040-09-09 15:00:00',
        'cancelled_at' => '2040-09-01 12:00:00',
    ]);
    EventSubstituteRequest::factory()->for($cancelledEvent)->create([
        'reason_type' => EventSubstituteRequestReason::Sick,
        'sick_instructor_id' => $instructor->id,
    ]);
    $service = app(FinanceReportService::class);
    $filters = ['academic_term_id' => ['value' => $term->id]];
    $dataset = $service->dataset(ReportKey::SickLeave, $owner, $filters);

    expect($dataset->rows)->toHaveCount(2)
        ->and(collect($dataset->rows)->firstWhere('course_name', 'Solo Ballet'))->toMatchArray([
            'instructor_name' => 'Taylor Teacher',
            'attribution_status' => 'Reconciled',
            'requested_by' => 'Olivia Owner',
            'enrollment_count' => 1,
        ])
        ->and(collect($dataset->rows)->firstWhere('course_name', 'Co-taught Tap'))->toMatchArray([
            'instructor_name' => '—',
            'attribution_status' => 'Unreconciled',
            'requested_by' => 'Olivia Owner',
        ]);

    $unreconciled = $service->dataset(ReportKey::SickLeave, $owner, [
        ...$filters,
        'attribution_status' => ['value' => 'unreconciled'],
    ]);

    expect($unreconciled->rows)->toHaveCount(1)
        ->and($unreconciled->rows[0]['course_name'])->toBe('Co-taught Tap');
});

it('renders both finance report tables', function (): void {
    $this->actingAs(User::factory()->isOwner()->create());

    livewire(PayrollReport::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('Number of Enrollments')
        ->assertSee('Assigned Instructor')
        ->assertSee('Sub Reason');

    livewire(SickLeaveReport::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('Attribution Status')
        ->assertSee('Requested By');
});

it('exports both finance reports through the shared private export pipeline', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create();
    $event = Event::factory()->for($course)->create([
        'start_time' => '2040-09-07 14:00:00',
        'end_time' => '2040-09-07 15:00:00',
    ]);
    EventSubstituteRequest::factory()->for($event)->create([
        'reason_type' => EventSubstituteRequestReason::Sick,
        'sick_instructor_id' => $course->teachers()->value('users.id'),
    ]);
    $states = [
        ReportKey::Payroll->value => [
            'date_range' => ['from' => '2040-09-01', 'through' => '2040-09-30'],
        ],
        ReportKey::SickLeave->value => [
            'academic_term_id' => ['value' => $term->id],
        ],
    ];

    foreach ([ReportKey::Payroll, ReportKey::SickLeave] as $report) {
        $export = ReportExport::factory()->for($owner)->create([
            'report_key' => $report,
            'format' => ReportExportFormat::Csv,
            'state' => [
                'filters' => $states[$report->value],
                'search' => null,
                'sort' => null,
                'columns' => [],
            ],
        ]);

        (new App\Jobs\GenerateReportExport($export))->handle(app(ReportExportService::class));
        $export->refresh();

        expect($export->status)->toBe(ReportExportStatus::Completed)
            ->and($export->total_rows)->toBe(1)
            ->and($export->path)->not->toBeNull();
        Storage::disk('local')->assertExists((string) $export->path);
    }
});
