<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\Reports\ReportDataset;
use App\Enums\CreditTransactionType;
use App\Enums\EventSubstituteRequestReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ReportKey;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\PaymentPlans\PaymentPlanBreakdownCalculator;
use App\Support\Store\AllocateOrderItemPayments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class FinanceReportService
{
    public function __construct(
        private AllocateOrderItemPayments $allocateOrderItemPayments,
        private PaymentPlanBreakdownCalculator $paymentPlanBreakdownCalculator,
    ) {}

    /** @return array{gross_enrollments: int, net_enrollment_purchases: int} */
    public function dashboard(?AcademicTerm $term): array
    {
        if (! $term instanceof AcademicTerm) {
            return ['gross_enrollments' => 0, 'net_enrollment_purchases' => 0];
        }

        $courseMorphClass = (new Course)->getMorphClass();
        $orders = Order::query()
            ->whereIn('status', [
                OrderStatus::Completed,
                OrderStatus::PartiallyRefunded,
                OrderStatus::Refunded,
            ])
            ->whereHas('orderItems', fn (Builder $query): Builder => $query
                ->where('status', OrderItemStatus::Fulfilled)
                ->whereHas('product', fn (Builder $query): Builder => $query
                    ->where('productable_type', $courseMorphClass)
                    ->whereHasMorph(
                        'productable',
                        [Course::class],
                        fn (Builder $query): Builder => $query->where('academic_term_id', $term->id),
                    )))
            ->with([
                'orderItems' => fn ($query) => $query->orderBy('id'),
                'orderItems.product.productable',
                'paymentPlanTemplate',
            ])
            ->get();
        $transactionsByOrder = $this->creditTransactionsByOrder($orders);
        $gross = 0;
        $net = 0;

        foreach ($orders as $order) {
            $allocations = $this->paymentAllocations(
                $order,
                $transactionsByOrder->get($order->id, collect()),
            );

            foreach ($order->orderItems as $item) {
                $course = $item->product->productable;

                if ($item->status !== OrderItemStatus::Fulfilled
                    || ! $course instanceof Course
                    || $course->academic_term_id !== $term->id) {
                    continue;
                }

                $gross += $item->total_price;
                $allocation = $allocations[$item->id] ?? $this->emptyAllocation();
                $net += max(
                    0,
                    $item->total_price
                        - $allocation['discount']
                        - $allocation['restricted_credit']
                        - $allocation['credit'],
                );
            }
        }

        return [
            'gross_enrollments' => $gross,
            'net_enrollment_purchases' => $net,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function dataset(ReportKey $report, User $user, array $filters): ReportDataset
    {
        return match ($report) {
            ReportKey::Payroll => $this->payroll($filters),
            ReportKey::SickLeave => $this->sickLeave($filters),
            default => throw new InvalidArgumentException("{$report->label()} is not a finance report."),
        };
    }

    public function currentTerm(): ?AcademicTerm
    {
        return AcademicTerm::query()->current()->orderByDesc('starts_on')->first();
    }

    /** @return array<string, array<int, string>> */
    public function academicTermOptions(): array
    {
        return AcademicTerm::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get()
            ->groupBy(fn (AcademicTerm $term): string => $term->academicYear->display_name)
            ->map(fn (Collection $terms): array => $terms
                ->mapWithKeys(fn (AcademicTerm $term): array => [$term->id => $term->display_name])
                ->all())
            ->all();
    }

    public function defaultPayrollDateFrom(): string
    {
        return now($this->displayTimezone())->subDays(13)->toDateString();
    }

    public function defaultPayrollDateThrough(): string
    {
        return now($this->displayTimezone())->toDateString();
    }

    /** @return array<string, string> */
    public function attributionStatusOptions(): array
    {
        return [
            'reconciled' => 'Reconciled',
            'unreconciled' => 'Unreconciled',
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, Collection<int, CreditTransaction>>
     */
    private function creditTransactionsByOrder(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        return CreditTransaction::query()
            ->where('reference_type', (new Order)->getMorphClass())
            ->whereIn('reference_id', $orders->modelKeys())
            ->where('type', CreditTransactionType::CheckoutDebit)
            ->with('creditGrant.products')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CreditTransaction $transaction): int => (int) $transaction->reference_id);
    }

    /**
     * @param  Collection<int, CreditTransaction>  $transactions
     * @return array<int, array{discount: int, restricted_credit: int, credit: int}>
     */
    private function paymentAllocations(Order $order, Collection $transactions): array
    {
        if ($this->hasReconciledAllocations($order)) {
            return $order->orderItems
                ->mapWithKeys(fn (OrderItem $item): array => [$item->id => [
                    'discount' => $item->discount_allocated,
                    'restricted_credit' => $item->restricted_credit_allocated,
                    'credit' => $item->credit_allocated,
                ]])
                ->all();
        }

        $amounts = $order->orderItems
            ->mapWithKeys(fn (OrderItem $item): array => [$item->id => $item->total_price])
            ->all();
        $discounts = $this->allocateOrderItemPayments->allocateProportionally(
            $amounts,
            $order->discount_amount,
        );
        $remaining = collect($amounts)
            ->map(fn (int $amount, int $itemId): int => max(0, $amount - ($discounts[$itemId] ?? 0)))
            ->all();
        $restrictedCredits = array_fill_keys(array_keys($amounts), 0);
        $restrictedOrderItems = $this->restrictedCreditOrderItems($order);

        foreach ($transactions as $transaction) {
            $grant = $transaction->creditGrant;

            if ($grant === null || ! $grant->hasRestrictions()) {
                continue;
            }

            $amount = min(
                abs($transaction->amount),
                max(0, $order->restricted_credit_applied - array_sum($restrictedCredits)),
            );

            foreach ($restrictedOrderItems as $item) {
                if ($amount <= 0) {
                    break;
                }

                if (! $grant->appliesToProduct($item->product)) {
                    continue;
                }

                $applied = min($remaining[$item->id] ?? 0, $amount);
                $remaining[$item->id] -= $applied;
                $restrictedCredits[$item->id] += $applied;
                $amount -= $applied;
            }
        }

        $this->allocateSequentially(
            $restrictedOrderItems,
            $remaining,
            $restrictedCredits,
            max(0, $order->restricted_credit_applied - array_sum($restrictedCredits)),
        );
        $credits = array_fill_keys(array_keys($amounts), 0);
        $this->allocateSequentially(
            $order->orderItems,
            $remaining,
            $credits,
            $order->credit_applied,
        );

        return collect($amounts)
            ->mapWithKeys(fn (int $amount, int $itemId): array => [$itemId => [
                'discount' => $discounts[$itemId] ?? 0,
                'restricted_credit' => $restrictedCredits[$itemId] ?? 0,
                'credit' => $credits[$itemId] ?? 0,
            ]])
            ->all();
    }

    private function hasReconciledAllocations(Order $order): bool
    {
        return (int) $order->orderItems->sum('discount_allocated') === $order->discount_amount
            && (int) $order->orderItems->sum('restricted_credit_allocated') === $order->restricted_credit_applied
            && (int) $order->orderItems->sum('credit_allocated') === $order->credit_applied;
    }

    /** @return Collection<int, OrderItem> */
    private function restrictedCreditOrderItems(Order $order): Collection
    {
        if ($order->paymentPlanTemplate === null) {
            return $order->orderItems;
        }

        return $this->paymentPlanBreakdownCalculator->itemsForCreditApplication(
            $order->orderItems,
            $order->paymentPlanTemplate,
        );
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @param  array<int, int>  $remaining
     * @param  array<int, int>  $allocated
     */
    private function allocateSequentially(
        Collection $items,
        array &$remaining,
        array &$allocated,
        int $amount,
    ): void {
        foreach ($items as $item) {
            if ($amount <= 0) {
                return;
            }

            $applied = min($remaining[$item->id] ?? 0, $amount);
            $remaining[$item->id] -= $applied;
            $allocated[$item->id] += $applied;
            $amount -= $applied;
        }
    }

    /** @return array{discount: int, restricted_credit: int, credit: int} */
    private function emptyAllocation(): array
    {
        return ['discount' => 0, 'restricted_credit' => 0, 'credit' => 0];
    }

    /** @param array<string, mixed> $filters */
    private function payroll(array $filters): ReportDataset
    {
        $headers = [
            'course_name' => 'Course Name',
            'enrollment_count' => 'Number of Enrollments',
            'event_date' => 'Event Date',
            'assigned_instructors' => 'Assigned Instructor(s)',
            'sub_instructor' => 'Sub Instructor',
            'sub_reason' => 'Sub Reason',
            'hours' => 'Hours',
        ];
        [$startsAt, $endsAt] = $this->payrollDateRange($filters);
        $rows = Event::query()
            ->whereNull('cancelled_at')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereBetween('start_time', [$startsAt, $endsAt])
            ->with([
                'course' => fn ($query) => $query->withCount('enrollments'),
                'course.teachers:id,first_name,last_name',
                'substituteTeachers:id,first_name,last_name',
                'substituteRequests',
            ])
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->map(function (Event $event): array {
                $startsAt = $event->start_time->copy()->timezone($this->displayTimezone());

                return [
                    '_key' => "event_{$event->id}",
                    'course_name' => $event->course?->name ?? $event->name,
                    'enrollment_count' => (int) ($event->course?->enrollments_count ?? 0),
                    'event_date' => $startsAt->toDateString(),
                    'assigned_instructors' => $this->assignedInstructorNames($event),
                    'sub_instructor' => $this->substituteInstructorNames($event),
                    'sub_reason' => $this->substituteReason($event),
                    'hours' => round(max(0.0, $event->start_time->diffInMinutes($event->end_time) / 60), 2),
                ];
            })
            ->all();

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function sickLeave(array $filters): ReportDataset
    {
        $headers = [
            'instructor_name' => 'Instructor',
            'attribution_status' => 'Attribution Status',
            'requested_by' => 'Requested By',
            'sick_leave_date' => 'Sick Leave Date',
            'course_name' => 'Course Name',
            'enrollment_count' => 'Number of Enrollments',
        ];
        $termId = $this->integerFilterValue($filters, 'academic_term_id');
        $status = $this->filterValue($filters, 'attribution_status');
        $requests = EventSubstituteRequest::query()
            ->where('reason_type', EventSubstituteRequestReason::Sick)
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->when($termId !== null, fn (Builder $query): Builder => $query
                    ->whereHas('course', fn (Builder $query): Builder => $query
                        ->where('academic_term_id', $termId))))
            ->with([
                'requestedBy:id,first_name,last_name',
                'sickInstructor:id,first_name,last_name',
                'event.course' => fn ($query) => $query->withCount('enrollments'),
            ])
            ->orderBy('id')
            ->get()
            ->unique('event_id');
        $rows = $requests
            ->filter(function (EventSubstituteRequest $request) use ($status): bool {
                if (! is_string($status) || $status === '') {
                    return true;
                }

                return $status === ($request->sick_instructor_id === null ? 'unreconciled' : 'reconciled');
            })
            ->map(function (EventSubstituteRequest $request): array {
                $event = $request->event;

                return [
                    '_key' => "event_{$event->id}",
                    'instructor_name' => $request->sickInstructor?->fullName ?? '—',
                    'attribution_status' => $request->sick_instructor_id === null ? 'Unreconciled' : 'Reconciled',
                    'requested_by' => $request->requestedBy?->fullName ?? '—',
                    'sick_leave_date' => $event->start_time?->copy()
                        ->timezone($this->displayTimezone())
                        ->toDateString() ?? '—',
                    'course_name' => $event->course?->name ?? $event->name,
                    'enrollment_count' => (int) ($event->course?->enrollments_count ?? 0),
                ];
            })
            ->values()
            ->all();

        return new ReportDataset($headers, $rows);
    }

    /** @param array<string, mixed> $filters */
    private function payrollDateRange(array $filters): array
    {
        $range = $filters['date_range'] ?? [];
        $from = is_array($range) ? ($range['from'] ?? null) : null;
        $through = is_array($range) ? ($range['through'] ?? null) : null;
        $from = is_string($from) && $from !== '' ? $from : $this->defaultPayrollDateFrom();
        $through = is_string($through) && $through !== '' ? $through : $this->defaultPayrollDateThrough();
        $startsAt = CarbonImmutable::parse($from, $this->displayTimezone())->startOfDay();
        $endsAt = CarbonImmutable::parse($through, $this->displayTimezone())->endOfDay();

        if ($endsAt->lt($startsAt)) {
            [$startsAt, $endsAt] = [$endsAt->startOfDay(), $startsAt->endOfDay()];
        }

        return [
            $startsAt->setTimezone((string) config('app.timezone', 'UTC')),
            $endsAt->setTimezone((string) config('app.timezone', 'UTC')),
        ];
    }

    private function assignedInstructorNames(Event $event): string
    {
        $names = $event->course?->teachers
            ->map(fn (User $teacher): string => $teacher->fullName)
            ->filter()
            ->values() ?? collect();

        if ($names->isNotEmpty()) {
            return $names->implode(', ');
        }

        return filled($event->course?->guest_teacher)
            ? (string) $event->course->guest_teacher
            : 'Unassigned';
    }

    private function substituteInstructorNames(Event $event): string
    {
        $names = $event->substituteTeachers
            ->map(fn (User $teacher): string => $teacher->fullName)
            ->filter()
            ->values();

        return $names->isNotEmpty() ? $names->implode(', ') : '—';
    }

    private function substituteReason(Event $event): string
    {
        $requests = $event->substituteRequests->sortByDesc('id');
        $reason = $requests->pluck('request_reason')->first(fn (mixed $reason): bool => filled($reason))
            ?? $requests->pluck('release_reason')->first(fn (mixed $reason): bool => filled($reason))
            ?? $requests->pluck('closure_reason')->first(fn (mixed $reason): bool => filled($reason));

        return is_string($reason) ? $reason : '—';
    }

    /** @param array<string, mixed> $filters */
    private function integerFilterValue(array $filters, string $name): ?int
    {
        $id = filter_var($this->filterValue($filters, $name), FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    /** @param array<string, mixed> $filters */
    private function filterValue(array $filters, string $name): mixed
    {
        $value = $filters[$name] ?? null;

        return is_array($value) ? ($value['value'] ?? null) : $value;
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone', 'UTC'));
    }
}
