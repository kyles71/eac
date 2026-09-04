<?php

declare(strict_types=1);

use App\Actions\Events\ManageEventSubstitution;
use App\Enums\EventSubstituteRequestReason;
use App\Enums\OrderStatus;
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
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\Order;
use App\Models\OrderItem;
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
        ->assertSee('Refunds are not included');
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
        ->assertSee('Net Enrollment Purchases');
});

it('calculates booked gross and net course purchases by academic term without deducting refunds', function (): void {
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
        'net_enrollment_purchases' => 12000,
    ]);
});

it('reconstructs missing legacy discount and credit allocations', function (): void {
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
        'net_enrollment_purchases' => 5000,
    ]);
});

it('includes past and future payroll events in range while always excluding cancellations', function (): void {
    $owner = User::factory()->isOwner()->create();
    $term = AcademicTerm::factory()->create();
    $course = Course::factory()->for($term)->create(['name' => 'Payroll Jazz']);
    $assigned = User::factory()->isTeacher()->create([
        'first_name' => 'Alex',
        'last_name' => 'Assigned',
    ]);
    $substitute = User::factory()->isTeacher()->create([
        'first_name' => 'Sam',
        'last_name' => 'Substitute',
    ]);
    $course->teachers()->sync([$assigned->id]);
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

    expect($dataset->rows)->toHaveCount(2)
        ->and($dataset->rows[0])->toMatchArray([
            'course_name' => 'Payroll Jazz',
            'enrollment_count' => 2,
            'assigned_instructors' => 'Alex Assigned',
            'sub_instructor' => 'Sam Substitute',
            'sub_reason' => 'Instructor unavailable',
            'hours' => 1.5,
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
        $instructor,
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
    EventSubstituteRequest::factory()->for($ambiguousEvent)->create([
        'requested_by_user_id' => $owner->id,
        'reason_type' => EventSubstituteRequestReason::Sick,
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
