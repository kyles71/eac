<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\Reports\ReportDataset;
use App\Enums\CreditTransactionType;
use App\Enums\EventSubstituteRequestReason;
use App\Enums\InstallmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Enums\ReportKey;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CreditGrant;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\EventSubstituteCoverage;
use App\Models\EventSubstituteRequest;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\PaymentPlans\PaymentPlanBreakdownCalculator;
use App\Support\Store\AllocateOrderItemPayments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class FinanceReportService
{
    public function __construct(
        private AllocateOrderItemPayments $allocateOrderItemPayments,
        private PaymentPlanBreakdownCalculator $paymentPlanBreakdownCalculator,
    ) {}

    /** @return array{gross_enrollments: int, net_enrollment_purchases: int, pending_payment_plan_income: int} */
    public function dashboard(?AcademicTerm $term): array
    {
        if (! $term instanceof AcademicTerm) {
            return [
                'gross_enrollments' => 0,
                'net_enrollment_purchases' => 0,
                'pending_payment_plan_income' => 0,
            ];
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
                'paymentPlan.installments',
                'paymentPlanTemplate',
                'refunds.payments',
            ])
            ->get();
        $transactionsByOrder = $this->creditTransactionsByOrder($orders);
        $gross = 0;
        $discounts = 0;
        $eligibleStoreCredit = 0;
        $refunds = 0;
        $pendingPaymentPlanIncome = 0;

        foreach ($orders as $order) {
            $allocations = $this->paymentAllocations(
                $order,
                $transactionsByOrder->get($order->id, collect()),
            );
            $eligibleStoreCreditAllocations = $this->eligibleStoreCreditAllocations(
                $order,
                $transactionsByOrder->get($order->id, collect()),
                $allocations,
            );
            $refundAllocations = $this->successfulRefundAllocations($order, $allocations);
            $pendingPaymentPlanAllocations = $this->pendingPaymentPlanAllocations($order, $allocations);
            [$paidPaymentPlanAmount, $paymentPlanTotal] = $this->paymentPlanProgress($order);

            foreach ($order->orderItems as $item) {
                $course = $item->product->productable;

                if ($item->status !== OrderItemStatus::Fulfilled
                    || ! $course instanceof Course
                    || $course->academic_term_id !== $term->id) {
                    continue;
                }

                $allocation = $allocations[$item->id] ?? $this->emptyAllocation();
                $usesPaymentPlan = $this->usesPaymentPlan($order, $item);
                $numerator = $usesPaymentPlan ? $paidPaymentPlanAmount : 1;
                $denominator = $usesPaymentPlan ? $paymentPlanTotal : 1;

                $gross += $this->prorate($item->total_price, $numerator, $denominator);
                $discounts += $this->prorate($allocation['discount'], $numerator, $denominator);
                $eligibleStoreCredit += $eligibleStoreCreditAllocations[$item->id] ?? 0;
                $refunds += $refundAllocations[$item->id] ?? 0;
                $pendingPaymentPlanIncome += $pendingPaymentPlanAllocations[$item->id] ?? 0;
            }
        }

        return [
            'gross_enrollments' => $gross,
            'net_enrollment_purchases' => $gross
                - $discounts
                - $eligibleStoreCredit
                - $refunds,
            'pending_payment_plan_income' => $pendingPaymentPlanIncome,
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
     * @param  EloquentCollection<int, Order>  $orders
     * @return Collection<int|string, Collection<int, CreditTransaction>>
     */
    private function creditTransactionsByOrder(EloquentCollection $orders): Collection
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
            ->toBase()
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

    /**
     * @param  Collection<int, CreditTransaction>  $transactions
     * @param  array<int, array{discount: int, restricted_credit: int, credit: int}>  $paymentAllocations
     * @return array<int, int>
     */
    private function eligibleStoreCreditAllocations(
        Order $order,
        Collection $transactions,
        array $paymentAllocations,
    ): array {
        $eligibleAllocations = array_fill_keys($order->orderItems->modelKeys(), 0);
        $remainingRestrictedAllocations = collect($paymentAllocations)
            ->map(fn (array $allocation): int => $allocation['restricted_credit'])
            ->all();
        $remainingUnrestrictedAllocations = collect($paymentAllocations)
            ->map(fn (array $allocation): int => $allocation['credit'])
            ->all();

        foreach ($transactions as $transaction) {
            $grant = $transaction->creditGrant;

            if (! $grant instanceof CreditGrant) {
                continue;
            }

            $allocated = $grant->hasRestrictions()
                ? $this->allocateCreditTransaction(
                    $order->orderItems,
                    $remainingRestrictedAllocations,
                    abs($transaction->amount),
                    $grant,
                )
                : $this->allocateCreditTransaction(
                    $order->orderItems,
                    $remainingUnrestrictedAllocations,
                    abs($transaction->amount),
                );

            if (! $this->isEligibleEnrollmentCredit($grant)) {
                continue;
            }

            foreach ($allocated as $itemId => $amount) {
                $eligibleAllocations[$itemId] += $amount;
            }
        }

        return $eligibleAllocations;
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @param  array<int, int>  $remainingAllocations
     * @return array<int, int>
     */
    private function allocateCreditTransaction(
        Collection $items,
        array &$remainingAllocations,
        int $amount,
        ?CreditGrant $restrictedGrant = null,
    ): array {
        $allocated = [];

        foreach ($items as $item) {
            if ($amount <= 0) {
                break;
            }

            if ($restrictedGrant instanceof CreditGrant && ! $restrictedGrant->appliesToProduct($item->product)) {
                continue;
            }

            $applied = min($remainingAllocations[$item->id] ?? 0, $amount);

            if ($applied <= 0) {
                continue;
            }

            $remainingAllocations[$item->id] -= $applied;
            $allocated[$item->id] = $applied;
            $amount -= $applied;
        }

        return $allocated;
    }

    private function isEligibleEnrollmentCredit(CreditGrant $grant): bool
    {
        $today = now($this->displayTimezone())->toDateString();

        return $grant->revoked_at === null
            && ($grant->expires_on === null || $grant->expires_on->toDateString() >= $today)
            && $grant->source_type !== (new GiftCard)->getMorphClass();
    }

    /** @return array{int, int} */
    private function paymentPlanProgress(Order $order): array
    {
        $paymentPlan = $order->paymentPlan;

        if ($paymentPlan === null || $paymentPlan->total_amount <= 0) {
            return [0, 1];
        }

        $paidAmount = (int) $paymentPlan->installments
            ->filter(fn ($installment): bool => $installment->status === InstallmentStatus::Paid)
            ->sum('amount');

        return [min($paidAmount, $paymentPlan->total_amount), $paymentPlan->total_amount];
    }

    private function usesPaymentPlan(Order $order, OrderItem $item): bool
    {
        return $order->paymentPlanTemplate?->matchesProduct($item->product, $item->total_price) ?? false;
    }

    private function prorate(int $amount, int $numerator, int $denominator): int
    {
        if ($amount <= 0 || $numerator <= 0 || $denominator <= 0) {
            return 0;
        }

        if ($numerator >= $denominator) {
            return $amount;
        }

        return intdiv(($amount * $numerator) + intdiv($denominator, 2), $denominator);
    }

    /**
     * @param  array<int, array{discount: int, restricted_credit: int, credit: int}>  $paymentAllocations
     * @return array<int, int>
     */
    private function pendingPaymentPlanAllocations(Order $order, array $paymentAllocations): array
    {
        $paymentPlan = $order->paymentPlan;

        if ($paymentPlan === null
            || $order->paymentPlanTemplate === null
            || $this->remainingInstallmentsAreBeingCancelled($order)) {
            return [];
        }

        $collectibleAmount = (int) $paymentPlan->installments
            ->filter(fn ($installment): bool => in_array($installment->status, [
                InstallmentStatus::Pending,
                InstallmentStatus::Failed,
                InstallmentStatus::Overdue,
            ], true))
            ->sum('amount');

        if ($collectibleAmount <= 0) {
            return [];
        }

        $amounts = $order->orderItems
            ->filter(fn (OrderItem $item): bool => $this->usesPaymentPlan($order, $item))
            ->mapWithKeys(function (OrderItem $item) use ($paymentAllocations): array {
                $allocation = $paymentAllocations[$item->id] ?? $this->emptyAllocation();

                return [$item->id => max(
                    0,
                    $item->total_price
                        - $allocation['discount']
                        - $allocation['restricted_credit']
                        - $allocation['credit'],
                )];
            })
            ->all();

        if ($order->payment_plan_fee > 0) {
            $amounts[0] = $order->payment_plan_fee;
        }

        $allocations = $this->allocateOrderItemPayments->allocateProportionally($amounts, $collectibleAmount);
        unset($allocations[0]);

        return $allocations;
    }

    private function remainingInstallmentsAreBeingCancelled(Order $order): bool
    {
        return $order->refunds->contains(
            fn ($refund): bool => $refund->cancel_remaining_installments
                && $refund->status !== OrderRefundStatus::Failed,
        );
    }

    /**
     * @param  array<int, array{discount: int, restricted_credit: int, credit: int}>  $paymentAllocations
     * @return array<int, int>
     */
    private function successfulRefundAllocations(Order $order, array $paymentAllocations): array
    {
        $refundedAmount = (int) $order->refunds
            ->flatMap->payments
            ->filter(fn ($payment): bool => $payment->status === OrderRefundPaymentStatus::Succeeded)
            ->sum('amount');

        if ($refundedAmount <= 0) {
            return [];
        }

        $amounts = $order->orderItems
            ->mapWithKeys(function (OrderItem $item) use ($paymentAllocations): array {
                $allocation = $paymentAllocations[$item->id] ?? $this->emptyAllocation();

                return [$item->id => max(
                    0,
                    $item->total_price
                        - $allocation['discount']
                        - $allocation['restricted_credit']
                        - $allocation['credit'],
                )];
            })
            ->all();

        if ($order->payment_plan_fee > 0) {
            $amounts[0] = $order->payment_plan_fee;
        }

        return $this->allocateOrderItemPayments->allocateProportionally($amounts, $refundedAmount);
    }

    /** @param array<string, mixed> $filters */
    private function payroll(array $filters): ReportDataset
    {
        $headers = [
            'course_name' => 'Course Name',
            'enrollment_count' => 'Number of Enrollments',
            'event_date' => 'Event Date',
            'assigned_instructors' => 'Assigned Instructor',
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
                'teachers:id,first_name,last_name',
                'substituteCoverages.substituteTeacher:id,first_name,last_name',
                'substituteCoverages.requests',
            ])
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->flatMap(function (Event $event): array {
                $startsAt = $event->start_time->copy()->timezone($this->displayTimezone());
                $course = $event->course_id !== null ? $event->course : null;
                $baseRow = [
                    'course_name' => $course instanceof Course ? $course->name : $event->name,
                    'enrollment_count' => $course instanceof Course ? (int) $course->enrollments_count : 0,
                    'event_date' => $startsAt->toDateString(),
                    'hours' => round(max(0.0, $event->start_time->diffInMinutes($event->end_time) / 60), 2),
                ];

                return collect($this->assignedInstructors($event))
                    ->map(function (array $instructor) use ($baseRow, $event): array {
                        $coverage = $this->payrollCoverage($event, $instructor['user_id']);
                        $substituteTeacher = $coverage instanceof EventSubstituteCoverage
                            && $coverage->substitute_teacher_id !== null
                                ? $coverage->substituteTeacher
                                : null;

                        return [
                            '_key' => "event_{$event->id}_{$instructor['key']}",
                            ...$baseRow,
                            'assigned_instructors' => $instructor['name'],
                            'sub_instructor' => $substituteTeacher instanceof User
                                ? $substituteTeacher->fullName
                                : '—',
                            'sub_reason' => $coverage instanceof EventSubstituteCoverage
                                ? $this->substituteReason($coverage)
                                : '—',
                        ];
                    })
                    ->all();
            })
            ->values()
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
            ->unique(fn (EventSubstituteRequest $request): string => $request->event_substitute_coverage_id !== null
                ? "coverage_{$request->event_substitute_coverage_id}"
                : "event_{$request->event_id}");
        $rows = $requests
            ->filter(function (EventSubstituteRequest $request) use ($status): bool {
                if (! is_string($status) || $status === '') {
                    return true;
                }

                return $status === ($request->sick_instructor_id === null ? 'unreconciled' : 'reconciled');
            })
            ->map(function (EventSubstituteRequest $request): array {
                $event = $request->event;
                $course = $event->course_id !== null ? $event->course : null;
                $sickInstructor = $request->sick_instructor_id !== null
                    ? $request->sickInstructor
                    : null;
                $requestedBy = $request->requested_by_user_id !== null
                    ? $request->requestedBy
                    : null;

                return [
                    '_key' => "substitute_request_{$request->id}",
                    'instructor_name' => $sickInstructor instanceof User ? $sickInstructor->fullName : '—',
                    'attribution_status' => $request->sick_instructor_id === null ? 'Unreconciled' : 'Reconciled',
                    'requested_by' => $requestedBy instanceof User ? $requestedBy->fullName : '—',
                    'sick_leave_date' => $event->start_time?->copy()
                        ->timezone($this->displayTimezone())
                        ->toDateString() ?? '—',
                    'course_name' => $course instanceof Course ? $course->name : $event->name,
                    'enrollment_count' => $course instanceof Course ? (int) $course->enrollments_count : 0,
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

    /** @return array<int, array{key: string, user_id: int|null, name: string}> */
    private function assignedInstructors(Event $event): array
    {
        $instructors = $event->teachers
            ->map(fn (User $teacher): array => $this->instructorAttribution(
                "teacher_{$teacher->id}",
                $teacher->id,
                $teacher->fullName,
            ))
            ->filter(fn (array $instructor): bool => filled($instructor['name']))
            ->values();

        if ($instructors->isNotEmpty()) {
            return $instructors->all();
        }

        $course = $event->course_id !== null ? $event->course : null;
        $guestTeacher = $course?->guest_teacher;

        if (filled($guestTeacher)) {
            return [$this->instructorAttribution(
                'guest_teacher',
                null,
                (string) $guestTeacher,
            )];
        }

        return [$this->instructorAttribution('unassigned', null, 'Unassigned')];
    }

    /** @return array{key: string, user_id: int|null, name: string} */
    private function instructorAttribution(string $key, ?int $userId, string $name): array
    {
        return [
            'key' => $key,
            'user_id' => $userId,
            'name' => $name,
        ];
    }

    private function payrollCoverage(Event $event, ?int $teacherId): ?EventSubstituteCoverage
    {
        $coverages = $event->substituteCoverages->sortByDesc('id');

        if ($teacherId !== null) {
            $coverage = $coverages->first(
                fn (EventSubstituteCoverage $coverage): bool => $coverage->covered_teacher_id === $teacherId,
            );

            if ($coverage instanceof EventSubstituteCoverage) {
                return $coverage;
            }
        }

        return $coverages->first(
            fn (EventSubstituteCoverage $coverage): bool => $coverage->covered_teacher_id === null,
        );
    }

    private function substituteReason(EventSubstituteCoverage $coverage): string
    {
        $requests = $coverage->requests->sortByDesc('id');
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
