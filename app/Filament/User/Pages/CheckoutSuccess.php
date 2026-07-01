<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Enrollments\AssignStudentToEnrollmentAction;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Filament\Shared\Schemas\ProductQuestionAnswerSchema;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\Students\Schemas\StudentForm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FormUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Student;
use App\Models\User;
use App\Support\UserAttention;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

final class CheckoutSuccess extends Page
{
    private const int MAX_STATUS_POLLS = 10;

    public ?Order $order = null;

    public ?string $redirectStatus = null;

    public int $statusPolls = 0;

    /** @var array<int, int|null> */
    public array $assignmentStudentIds = [];

    protected static ?string $title = 'Order Confirmation';

    protected static ?string $slug = 'checkout/success';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $paymentIntent = request()->query('payment_intent');
        $orderId = request()->query('order_id');
        $redirectStatus = request()->query('redirect_status');
        $this->redirectStatus = is_string($redirectStatus)
            ? $redirectStatus
            : null;

        if ($paymentIntent !== null) {
            $this->order = Order::query()
                ->where('user_id', auth()->id())
                ->where('stripe_payment_intent_id', $paymentIntent)
                ->with($this->orderRelationships())
                ->first();
        } elseif ($orderId !== null) {
            $this->order = Order::query()
                ->where('user_id', auth()->id())
                ->where('id', $orderId)
                ->with($this->orderRelationships())
                ->first();
        }

        if ($this->redirectStatus === 'succeeded' && $this->order?->status === OrderStatus::Processing) {
            $this->order->clearPurchasedCartItems();
            $this->dispatch('refresh-sidebar');
        }

        $this->syncAssignmentStudentIds();
    }

    public function refreshOrderStatus(): void
    {
        if ($this->order === null || ! $this->isFinalizingPayment || $this->statusPolls >= self::MAX_STATUS_POLLS) {
            return;
        }

        $wasFinalizing = $this->isFinalizingPayment;
        $this->statusPolls++;

        $this->order = Order::query()
            ->where('user_id', auth()->id())
            ->where('id', $this->order->id)
            ->with($this->orderRelationships())
            ->first();

        $this->syncAssignmentStudentIds();

        unset($this->isFinalizingPayment, $this->hasExceededStatusPolling);

        if ($wasFinalizing && ! $this->isFinalizingPayment) {
            $this->dispatchAttentionUpdated();
        }
    }

    public function getIsFinalizingPaymentProperty(): bool
    {
        return $this->redirectStatus === 'succeeded'
            && $this->order?->status === OrderStatus::Processing;
    }

    public function getHasExceededStatusPollingProperty(): bool
    {
        return $this->isFinalizingPayment && $this->statusPolls >= self::MAX_STATUS_POLLS;
    }

    public function content(Schema $schema): Schema
    {
        if ($this->order === null) {
            return $schema
                ->components([
                    Section::make('Order Not Found')
                        ->schema([
                            TextEntry::make('message')
                                ->hiddenLabel()
                                ->state('We could not find your order. Please check your email for confirmation or contact support.'),
                        ]),
                ]);
        }

        $components = [
            Section::make('Payment Finalizing')
                ->schema([
                    TextEntry::make('payment_finalizing_message')
                        ->hiddenLabel()
                        ->state(fn (): string => $this->hasExceededStatusPolling
                            ? 'Your payment was submitted successfully and is taking a little longer than usual to finish confirming. This page is safe to refresh, and your order status will update as soon as confirmation is complete.'
                            : 'Your payment was submitted successfully. We are confirming the final details now, and this page will update automatically.'),
                ])
                ->visible(fn (): bool => $this->isFinalizingPayment)
                ->extraAttributes(['wire:poll.2s' => 'refreshOrderStatus']),
            Grid::make([
                'default' => 1,
                'md' => 2,
            ])
                ->schema([
                    Section::make(fn (): string|HtmlString => $this->hasOrderCourseEnrollments()
                        ? new HtmlString('<span class="md:hidden">Order Details - NEXT STEPS BELOW</span><span class="hidden md:inline">Order Details</span>')
                        : 'Order Details')
                        ->schema([
                            TextEntry::make('order_number')
                                ->label('Order Number')
                                ->state(fn (): string => "#{$this->order->id}"),
                            TextEntry::make('status')
                                ->label('Status')
                                ->state($this->order->status)
                                ->badge(),
                            TextEntry::make('total')
                                ->label('Total Paid')
                                ->state(fn (): string => format_money($this->order->amountPaidAtCheckout())),
                            TextEntry::make('date')
                                ->label('Date')
                                ->state(fn () => $this->order->created_at)
                                ->dateTime('M j, Y g:i A'),
                        ])
                        ->columnSpan(fn (): array => $this->hasOrderCourseEnrollments()
                            ? ['default' => 1, 'md' => 1]
                            : ['default' => 'full', 'md' => 'full'])
                        ->extraAttributes(['class' => 'h-full [&>.fi-section]:h-full']),
                    Grid::make(1)
                        ->schema([
                            Section::make('Next Step')
                                ->schema(fn (): array => $this->getCourseEnrollmentSchema())
                                ->visible(fn (): bool => $this->hasOrderCourseEnrollments())
                                ->columnSpanFull()
                                ->extraAttributes(['class' => 'h-full [&>.fi-section]:h-full']),
                            Section::make('Required Forms')
                                ->schema(fn (): array => $this->getRequiredFormsSchema())
                                ->visible(fn (): bool => $this->pendingFormsForOrderStudents()->isNotEmpty())
                                ->columnSpanFull(),
                        ])
                        ->visible(fn (): bool => $this->hasOrderCourseEnrollments())
                        ->extraAttributes(['class' => 'h-full [&>.fi-sc]:h-full']),
                ]),
            Section::make('Payment Plan Details')
                ->schema($this->getPaymentPlanDetailsSchema())
                ->visible(fn (): bool => $this->order->paymentPlanTemplate !== null),
            Section::make('Items Purchased')
                ->schema(function (): array {
                    $components = [];

                    foreach ($this->order->orderItems as $item) {
                        /** @var OrderItem $item */
                        /** @var \App\Models\Product $product */
                        $product = $item->product;

                        $components[] = TextEntry::make("item_{$item->id}")
                            ->label($product->name)
                            ->state(fn (): string => "Qty: {$item->quantity} × {$item->formattedUnitPrice()} = {$item->formattedTotalPrice()}");

                        array_push(
                            $components,
                            ...ProductQuestionAnswerSchema::forOrderItem($item, 'confirmation'),
                        );
                    }

                    return $components;
                }),
        ];

        return $schema->components($components);
    }

    public function saveCourseAssignments(): void
    {
        if ($this->order === null || $this->order->status !== OrderStatus::Completed) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $enrollments = $this->unassignedCourseEnrollments();
        $students = $this->studentsBySelectedEnrollment($enrollments, $user);

        if ($students === null) {
            return;
        }

        try {
            /** @var Enrollment $enrollment */
            foreach ($enrollments as $enrollment) {
                app(AssignStudentToEnrollmentAction::class)->handle(
                    $enrollment,
                    $students[$enrollment->id],
                    $user,
                );
            }
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Could not update enrollment')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->reloadOrder();
        $this->dispatchAttentionUpdated();

        Notification::make()
            ->title('Course enrollment updated')
            ->body($this->pendingFormsForOrderStudents()->isNotEmpty()
                ? 'Required forms are ready below.'
                : 'You are all set for this course.')
            ->success()
            ->send();
    }

    public function saveCourseAssignmentsAction(): Action
    {
        return Action::make('saveCourseAssignments')
            ->label('Save Course Assignments')
            ->icon(Heroicon::OutlinedUserPlus)
            ->disabled(fn (): bool => $this->hasMissingAssignmentSelections())
            ->action(function (): void {
                $this->saveCourseAssignments();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewEnrollments')
                ->label('View My Classes')
                ->icon(Heroicon::OutlinedAcademicCap)
                ->url(MyEnrollments::getUrl()),
            Action::make('continueShopping')
                ->label('Continue Shopping')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('gray')
                ->url(Store::getUrl()),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private function getPaymentPlanDetailsSchema(): array
    {
        $template = $this->order?->paymentPlanTemplate;

        if ($template === null || $this->order === null) {
            return [];
        }

        $amountPaidToday = $this->order->amountPaidAtCheckout();
        $remainingBalance = max(0, $this->order->total - $amountPaidToday);
        $nextInstallment = $this->order->paymentPlan?->installments
            ->where('status', InstallmentStatus::Pending)
            ->sortBy('due_date')
            ->first();

        return [
            TextEntry::make('payment_plan_schedule')
                ->label('Schedule')
                ->state("{$template->number_of_installments} {$template->frequency->value} payments"),
            TextEntry::make('payment_plan_fee')
                ->label('Payment Plan Fee')
                ->state($this->order->formattedPaymentPlanFee()),
            TextEntry::make('payment_plan_total')
                ->label('Plan Total')
                ->state($this->order->formattedTotal()),
            TextEntry::make('payment_plan_paid_today')
                ->label('Paid Today')
                ->state(format_money($amountPaidToday)),
            TextEntry::make('payment_plan_remaining')
                ->label('Remaining Balance')
                ->state(format_money($remainingBalance)),
            TextEntry::make('payment_plan_next_payment')
                ->label('Next Payment')
                ->state($nextInstallment !== null
                    ? format_money($nextInstallment->amount).' due '.$nextInstallment->due_date->format('M j, Y')
                    : 'No upcoming payments'),
        ];
    }

    /**
     * @return array<\Filament\Schemas\Components\Component|Action>
     */
    private function getCourseEnrollmentSchema(): array
    {
        $unassignedEnrollments = $this->unassignedCourseEnrollments();

        $schema = [
            TextEntry::make('course_enrollment_message')
                ->hiddenLabel()
                ->state($unassignedEnrollments->isEmpty()
                    ? 'Thanks, your course enrollment has been assigned.'
                    : 'Thanks for enrolling in a course. Let us know which student will be taking each class so we can finish enrollment.'),
        ];

        if ($unassignedEnrollments->isEmpty()) {
            return $schema;
        }

        $schema[] = Grid::make()
            ->columns([
                'default' => 1,
                'md' => 2,
            ])
            ->schema(
                $unassignedEnrollments
                    ->map(fn (Enrollment $enrollment): Select => $this->studentAssignmentSelect($enrollment))
                    ->all()
            )
            ->columnSpanFull();

        $schema[] = Actions::make([
            $this->saveCourseAssignmentsAction,
        ])
            ->fullWidth()
            ->columnSpanFull();

        return $schema;
    }

    /**
     * @return array<\Filament\Schemas\Components\Component|Action>
     */
    private function getRequiredFormsSchema(): array
    {
        $pendingForms = $this->pendingFormsForOrderStudents();

        if ($pendingForms->isEmpty()) {
            return [];
        }

        return [
            TextEntry::make('required_forms_message')
                ->hiddenLabel()
                ->state('Some required forms are ready for the students assigned to this course purchase.'),
            Actions::make(
                $pendingForms
                    ->map(fn (FormUser $formUser): Action => $this->completeFormAction($formUser))
                    ->all()
            )
                ->fullWidth()
                ->columnSpanFull(),
        ];
    }

    private function studentAssignmentSelect(Enrollment $enrollment): Select
    {
        return Select::make("assignmentStudentIds.{$enrollment->id}")
            ->label($this->assignmentLabel($enrollment))
            ->helperText($enrollment->course?->firstMeetingStartsAt()?->format('M j, Y g:i A'))
            ->options(fn (): array => $this->studentOptions())
            ->required()
            ->searchable()
            ->live()
            ->createOptionForm(fn (Schema $schema): Schema => StudentForm::configure($schema))
            ->createOptionUsing(function (array $data): int {
                /** @var User $user */
                $user = auth()->user();

                return $user->students()->create($data)->getKey();
            });
    }

    private function completeFormAction(FormUser $formUser): Action
    {
        $formUser->loadMissing(['form', 'student']);

        $label = $formUser->student === null
            ? "Complete {$formUser->form->name}"
            : "Complete {$formUser->form->name} for {$formUser->student->first_name}";

        return Action::make("completeForm{$formUser->id}")
            ->label($label)
            ->icon(Heroicon::OutlinedDocumentText)
            ->url(FormUserResource::getUrl('edit', ['record' => $formUser]));
    }

    private function assignmentLabel(Enrollment $enrollment): string
    {
        $courseName = $enrollment->course?->name ?? 'Course';
        $sameCourseEnrollments = $this->unassignedCourseEnrollments()
            ->where('course_id', $enrollment->course_id)
            ->values();

        if ($sameCourseEnrollments->count() <= 1) {
            return $courseName;
        }

        $seatNumber = $sameCourseEnrollments
            ->search(fn (Enrollment $courseEnrollment): bool => $courseEnrollment->id === $enrollment->id);

        return $courseName.' seat '.((int) $seatNumber + 1);
    }

    /**
     * @return array<int, string>
     */
    private function studentOptions(): array
    {
        return Student::query()
            ->where('user_id', auth()->id())
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (Student $student): array => [$student->id => $student->fullName])
            ->all();
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function orderCourseEnrollments(): Collection
    {
        if ($this->order?->status !== OrderStatus::Completed) {
            return collect();
        }

        return $this->order->orderItems
            ->filter(fn (OrderItem $orderItem): bool => $orderItem->product?->productable instanceof Course)
            ->flatMap(fn (OrderItem $orderItem): Collection => $orderItem->enrollments)
            ->sortBy('id')
            ->values();
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function unassignedCourseEnrollments(): Collection
    {
        return $this->orderCourseEnrollments()
            ->filter(fn (Enrollment $enrollment): bool => $enrollment->student_id === null)
            ->values();
    }

    private function hasOrderCourseEnrollments(): bool
    {
        return $this->orderCourseEnrollments()->isNotEmpty();
    }

    /**
     * @return Collection<int, FormUser>
     */
    private function pendingFormsForOrderStudents(): Collection
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        $studentIds = $this->orderCourseEnrollments()
            ->pluck('student_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $requiredFormIdsByStudent = $this->requiredFormIdsByOrderStudent();

        if ($requiredFormIdsByStudent === []) {
            return collect();
        }

        return app(UserAttention::class)
            ->pendingFormsForStudents($user, $studentIds)
            ->filter(function (FormUser $formUser) use ($requiredFormIdsByStudent): bool {
                $studentId = $formUser->student_id;

                return $studentId !== null
                    && in_array($formUser->form_id, $requiredFormIdsByStudent[$studentId] ?? [], true);
            })
            ->values();
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array<int, Student>|null
     */
    private function studentsBySelectedEnrollment(Collection $enrollments, User $user): ?array
    {
        $students = [];

        /** @var Enrollment $enrollment */
        foreach ($enrollments as $enrollment) {
            $studentId = (int) ($this->assignmentStudentIds[$enrollment->id] ?? 0);

            if ($studentId <= 0) {
                Notification::make()
                    ->title('Choose a student for each course')
                    ->danger()
                    ->send();

                return null;
            }

            $student = Student::query()
                ->where('user_id', $user->id)
                ->find($studentId);

            if ($student === null) {
                Notification::make()
                    ->title('Student not found')
                    ->danger()
                    ->send();

                return null;
            }

            $students[$enrollment->id] = $student;
        }

        return $students;
    }

    private function hasMissingAssignmentSelections(): bool
    {
        return $this->unassignedCourseEnrollments()
            ->contains(fn (Enrollment $enrollment): bool => blank($this->assignmentStudentIds[$enrollment->id] ?? null));
    }

    /**
     * @return array<int, array<int, int>>
     */
    private function requiredFormIdsByOrderStudent(): array
    {
        $formIdsByStudent = [];

        /** @var Enrollment $enrollment */
        foreach ($this->orderCourseEnrollments()->whereNotNull('student_id') as $enrollment) {
            $studentId = $enrollment->student_id;

            if ($studentId === null) {
                continue;
            }

            $courseFormIds = $enrollment->course?->forms->pluck('id')->all() ?? [];

            $formIdsByStudent[$studentId] = array_values(array_unique([
                ...($formIdsByStudent[$studentId] ?? []),
                ...$courseFormIds,
            ]));
        }

        return $formIdsByStudent;
    }

    private function syncAssignmentStudentIds(): void
    {
        $unassignedEnrollmentIds = $this->unassignedCourseEnrollments()
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();

        foreach ($unassignedEnrollmentIds as $enrollmentId) {
            if (! array_key_exists($enrollmentId, $this->assignmentStudentIds)) {
                $this->assignmentStudentIds[$enrollmentId] = null;
            }
        }

        $this->assignmentStudentIds = collect($this->assignmentStudentIds)
            ->filter(fn (mixed $value, int|string $enrollmentId): bool => in_array((int) $enrollmentId, $unassignedEnrollmentIds, true))
            ->all();
    }

    private function reloadOrder(): void
    {
        if ($this->order === null) {
            return;
        }

        $this->order = Order::query()
            ->where('user_id', auth()->id())
            ->where('id', $this->order->id)
            ->with($this->orderRelationships())
            ->first();

        $this->syncAssignmentStudentIds();
    }

    private function dispatchAttentionUpdated(): void
    {
        $this->dispatch(UserAttention::UPDATED_EVENT);
        $this->dispatch('refresh-sidebar');
    }

    /**
     * @return list<string>
     */
    private function orderRelationships(): array
    {
        return [
            'orderItems.enrollments.course.forms',
            'orderItems.enrollments.course',
            'orderItems.enrollments.student',
            'orderItems.product.productable',
            'orderItems.questionAnswers',
            'paymentPlan.installments',
            'paymentPlanTemplate',
        ];
    }
}
