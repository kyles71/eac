<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Actions\Mail\SendOrderFulfillmentEmail;
use App\Actions\Store\ReconcileReopenedFulfillmentEvent;
use App\Actions\Store\RecordOrderItemFulfillment;
use App\Actions\Store\VoidOrderItemFulfillment;
use App\Enums\FulfillmentWorkflow;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Shared\Schemas\PeopleAndGroupsPicker;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\OrderItem;
use App\Models\OrderItemFulfillment;
use App\Models\User;
use App\Support\LocationNameGuidance;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

final class OrderFulfillment extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Order Fulfillment';

    protected string $view = 'filament.admin.resources.orders.pages.order-fulfillment';

    public static function canAccess(array $parameters = []): bool
    {
        return parent::canAccess($parameters)
            && (auth()->user()?->can('Fulfill:Order') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->fulfillmentQuery())
            ->recordUrl(fn (OrderItem $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
            ->columns([
                TextColumn::make('order.id')
                    ->label('Order #')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('order.user.full_name')
                    ->label('Customer')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Purchased')
                    ->badge(),
                TextColumn::make('fulfilled_quantity')
                    ->label('Fulfilled')
                    ->state(fn (OrderItem $record): int => $record->fulfilledQuantity())
                    ->badge()
                    ->color('success'),
                TextColumn::make('remaining_quantity')
                    ->label('Remaining')
                    ->state(fn (OrderItem $record): int => $record->remainingQuantity())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('fulfillment_workflow')
                    ->label('Workflow')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('order.created_at')
                    ->label('Ordered')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(OrderItemStatus::class)
                    ->default([
                        OrderItemStatus::Pending->value,
                        OrderItemStatus::PartiallyFulfilled->value,
                    ]),
                SelectFilter::make('fulfillment_workflow')
                    ->label('Workflow')
                    ->options(FulfillmentWorkflow::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->viewOrderAction(),
                    $this->changeWorkflowAction(),
                    $this->manualFulfillmentAction(),
                    $this->createEventAction(),
                    $this->attachEventAction(),
                    $this->reopenFulfillmentAction(),
                ]),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->bulkChangeWorkflowAction(),
                    $this->bulkManualFulfillmentAction(),
                    $this->bulkCreateEventAction(),
                    $this->bulkAttachEventAction(),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(fn (OrderItem $record): bool => $record->remainingQuantity() > 0
                && in_array($record->order->status, [OrderStatus::Completed, OrderStatus::PartiallyRefunded], true));
    }

    /** @return Builder<OrderItem> */
    private function fulfillmentQuery(): Builder
    {
        return OrderItem::query()
            ->with([
                'order.user.students',
                'product',
                'questionAnswers',
                'fulfillments.source',
                'fulfillments.fulfilledBy',
                'fulfillments.voidedBy',
            ])
            ->whereHas('order', fn (Builder $query): Builder => $query->whereIn('status', [
                OrderStatus::Completed,
                OrderStatus::PartiallyRefunded,
            ]));
    }

    private function viewOrderAction(): Action
    {
        return Action::make('viewOrder')
            ->label('View Order')
            ->icon(Heroicon::OutlinedEye)
            ->url(fn (OrderItem $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]));
    }

    private function changeWorkflowAction(): Action
    {
        return Action::make('changeFulfillmentWorkflow')
            ->label('Change Workflow')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (OrderItem $record): bool => $record->fulfillment_workflow !== FulfillmentWorkflow::Automatic
                && $record->remainingQuantity() > 0
                && $record->activeFulfillments()->doesntExist())
            ->schema(fn (OrderItem $record): array => [
                Select::make('fulfillment_workflow')
                    ->label('Fulfillment Workflow')
                    ->options(FulfillmentWorkflow::configurableOptions())
                    ->default($record->fulfillment_workflow->value)
                    ->required()
                    ->searchable(false)
                    ->selectablePlaceholder(false),
            ])
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->action(function (array $data, OrderItem $record): void {
                Gate::authorize('fulfill', $record->order);
                $this->changeWorkflow($record, $data);
                $this->success('Fulfillment workflow changed');
            });
    }

    private function manualFulfillmentAction(): Action
    {
        return Action::make('recordManualFulfillment')
            ->label('Record Fulfillment')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (OrderItem $record): bool => $record->fulfillment_workflow === FulfillmentWorkflow::Manual
                && $record->remainingQuantity() > 0)
            ->schema(fn (OrderItem $record): array => [
                $this->unitSelection($record),
                Textarea::make('note')
                    ->label('Internal Note')
                    ->rows(3),
            ])
            ->action(function (array $data, OrderItem $record): void {
                Gate::authorize('fulfill', $record->order);

                app(RecordOrderItemFulfillment::class)->handle(
                    orderItem: $record,
                    unitNumbers: $this->unitNumbers($data),
                    fulfilledBy: $this->authenticatedUser(),
                    note: $data['note'] ?? null,
                );

                $this->success('Fulfillment recorded');
            });
    }

    private function createEventAction(): Action
    {
        return Action::make('createFulfillmentEvent')
            ->label('Create Event')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (OrderItem $record): bool => $record->fulfillment_workflow === FulfillmentWorkflow::ScheduledEvent
                && $record->remainingQuantity() > 0)
            ->modalHeading('Create Fulfillment Event')
            ->modalWidth('5xl')
            ->schema(fn (OrderItem $record): array => [
                $this->purchaserAnswers($record),
                $this->unitSelection($record),
                ...$this->eventCreationFields($record),
                ...$this->attendeeFields($record),
            ])
            ->action(function (array $data, OrderItem $record): void {
                Gate::authorize('fulfill', $record->order);
                Gate::authorize('create', Event::class);

                $event = DB::transaction(function () use ($data, $record): Event {
                    $event = $this->createEvent($data);
                    $this->addEventAttendees($event, $data);
                    $fulfillments = app(RecordOrderItemFulfillment::class)->handle(
                        orderItem: $record,
                        unitNumbers: $this->unitNumbers($data),
                        fulfilledBy: $this->authenticatedUser(),
                        source: $event,
                        studentIds: $this->studentIds($data),
                    );
                    app(SendOrderFulfillmentEmail::class)->scheduled($event, $fulfillments);

                    return $event;
                });

                $this->success('Event created and fulfillment recorded', EventResource::getUrl('view', ['record' => $event]));
            });
    }

    private function attachEventAction(): Action
    {
        return Action::make('attachFulfillmentEvent')
            ->label('Attach Existing Event')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (OrderItem $record): bool => $record->fulfillment_workflow === FulfillmentWorkflow::ScheduledEvent
                && $record->remainingQuantity() > 0)
            ->modalWidth('3xl')
            ->schema(fn (OrderItem $record): array => [
                $this->purchaserAnswers($record),
                $this->unitSelection($record),
                $this->eventSelection(),
                ...$this->attendeeFields($record),
            ])
            ->action(function (array $data, OrderItem $record): void {
                Gate::authorize('fulfill', $record->order);
                $event = $this->selectedEvent($data);
                Gate::authorize('update', $event);

                DB::transaction(function () use ($data, $event, $record): void {
                    $this->addEventAttendees($event, $data);
                    $fulfillments = app(RecordOrderItemFulfillment::class)->handle(
                        orderItem: $record,
                        unitNumbers: $this->unitNumbers($data),
                        fulfilledBy: $this->authenticatedUser(),
                        source: $event,
                        studentIds: $this->studentIds($data),
                    );
                    app(SendOrderFulfillmentEmail::class)->scheduled($event, $fulfillments);
                });

                $this->success('Event attached and fulfillment recorded', EventResource::getUrl('view', ['record' => $event]));
            });
    }

    private function reopenFulfillmentAction(): Action
    {
        return Action::make('reopenFulfillment')
            ->label('Reopen Fulfillment')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (OrderItem $record): bool => $record->activeFulfillments()->exists())
            ->schema(fn (OrderItem $record): array => [
                CheckboxList::make('fulfillment_ids')
                    ->label('Fulfilled Units')
                    ->options($this->activeFulfillmentOptions($record))
                    ->required(),
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Input reason for cancelling as well as what reschedule solution is, or if EAC will be in touch with rescheduling options. Reason is visible to user / parent.')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (array $data, OrderItem $record): void {
                Gate::authorize('fulfill', $record->order);
                $fulfillments = $record->activeFulfillments()
                    ->with('source')
                    ->whereKey(array_map('intval', $data['fulfillment_ids'] ?? []))
                    ->get();
                $reopenedFulfillments = new Collection;
                $reason = (string) str((string) $data['reason'])->squish();

                foreach ($fulfillments as $fulfillment) {
                    $wasReopened = app(VoidOrderItemFulfillment::class)->handle(
                        fulfillment: $fulfillment,
                        voidedBy: $this->authenticatedUser(),
                        reason: $reason,
                    );

                    if ($wasReopened) {
                        $reopenedFulfillments->push($fulfillment);
                    }
                }

                foreach ($reopenedFulfillments
                    ->filter(fn (OrderItemFulfillment $fulfillment): bool => $fulfillment->source instanceof Event)
                    ->groupBy('source_id') as $eventFulfillments) {
                    $event = $eventFulfillments->first()?->source;

                    if ($event instanceof Event) {
                        app(ReconcileReopenedFulfillmentEvent::class)->handle(
                            $event,
                            $eventFulfillments,
                            $this->authenticatedUser(),
                            $reason,
                        );
                        app(SendOrderFulfillmentEmail::class)->reopened(
                            $event,
                            $eventFulfillments,
                            $reason,
                        );
                    }
                }

                $this->success('Fulfillment reopened');
            });
    }

    private function bulkManualFulfillmentAction(): BulkAction
    {
        return BulkAction::make('recordManualFulfillmentBulk')
            ->label('Record Manual Fulfillment')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->schema([
                Textarea::make('note')
                    ->label('Internal Note')
                    ->rows(3),
            ])
            ->action(function (array $data, Collection $records): void {
                $this->ensureBulkWorkflow($records, FulfillmentWorkflow::Manual);

                foreach ($records as $record) {
                    Gate::authorize('fulfill', $record->order);
                    app(RecordOrderItemFulfillment::class)->handle(
                        orderItem: $record,
                        unitNumbers: $record->remainingUnitNumbers(),
                        fulfilledBy: $this->authenticatedUser(),
                        note: $data['note'] ?? null,
                    );
                }

                $this->success('Manual fulfillment recorded');
            })
            ->deselectRecordsAfterCompletion();
    }

    private function bulkChangeWorkflowAction(): BulkAction
    {
        return BulkAction::make('changeFulfillmentWorkflowBulk')
            ->label('Change Workflow')
            ->icon(Heroicon::OutlinedArrowPath)
            ->schema([
                Select::make('fulfillment_workflow')
                    ->label('Fulfillment Workflow')
                    ->options(FulfillmentWorkflow::configurableOptions())
                    ->required()
                    ->selectablePlaceholder(false),
            ])
            ->action(function (array $data, Collection $records): void {
                DB::transaction(function () use ($data, $records): void {
                    foreach ($records as $record) {
                        Gate::authorize('fulfill', $record->order);
                        $this->changeWorkflow($record, $data);
                    }
                });

                $this->success('Fulfillment workflows changed');
            })
            ->deselectRecordsAfterCompletion();
    }

    private function bulkCreateEventAction(): BulkAction
    {
        return BulkAction::make('createFulfillmentEventBulk')
            ->label('Create Shared Event')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->modalWidth('5xl')
            ->schema([
                ...$this->eventCreationFields(),
                ...$this->attendeeFields(),
            ])
            ->action(function (array $data, Collection $records): void {
                $this->ensureBulkWorkflow($records, FulfillmentWorkflow::ScheduledEvent);
                Gate::authorize('create', Event::class);

                $event = DB::transaction(function () use ($data, $records): Event {
                    $event = $this->createEvent($data);
                    $this->addEventAttendees($event, $data);

                    foreach ($records as $record) {
                        Gate::authorize('fulfill', $record->order);
                        $fulfillments = app(RecordOrderItemFulfillment::class)->handle(
                            orderItem: $record,
                            unitNumbers: $record->remainingUnitNumbers(),
                            fulfilledBy: $this->authenticatedUser(),
                            source: $event,
                            studentIds: $this->studentIds($data),
                        );
                        app(SendOrderFulfillmentEmail::class)->scheduled($event, $fulfillments);
                    }

                    return $event;
                });

                $this->success('Shared event created and fulfillment recorded', EventResource::getUrl('view', ['record' => $event]));
            })
            ->deselectRecordsAfterCompletion();
    }

    private function bulkAttachEventAction(): BulkAction
    {
        return BulkAction::make('attachFulfillmentEventBulk')
            ->label('Attach Shared Event')
            ->icon(Heroicon::OutlinedLink)
            ->modalWidth('3xl')
            ->schema([
                $this->eventSelection(),
                ...$this->attendeeFields(),
            ])
            ->action(function (array $data, Collection $records): void {
                $this->ensureBulkWorkflow($records, FulfillmentWorkflow::ScheduledEvent);
                $event = $this->selectedEvent($data);
                Gate::authorize('update', $event);

                DB::transaction(function () use ($data, $event, $records): void {
                    $this->addEventAttendees($event, $data);

                    foreach ($records as $record) {
                        Gate::authorize('fulfill', $record->order);
                        $fulfillments = app(RecordOrderItemFulfillment::class)->handle(
                            orderItem: $record,
                            unitNumbers: $record->remainingUnitNumbers(),
                            fulfilledBy: $this->authenticatedUser(),
                            source: $event,
                            studentIds: $this->studentIds($data),
                        );
                        app(SendOrderFulfillmentEmail::class)->scheduled($event, $fulfillments);
                    }
                });

                $this->success('Shared event attached and fulfillment recorded', EventResource::getUrl('view', ['record' => $event]));
            })
            ->deselectRecordsAfterCompletion();
    }

    private function unitSelection(OrderItem $orderItem): CheckboxList
    {
        return CheckboxList::make('unit_numbers')
            ->label('Units to Fulfill')
            ->options(collect($orderItem->remainingUnitNumbers())->mapWithKeys(fn (int $unitNumber): array => [
                $unitNumber => $this->unitLabel($orderItem, $unitNumber),
            ])->all())
            ->default($orderItem->remainingUnitNumbers())
            ->required();
    }

    private function purchaserAnswers(OrderItem $orderItem): Textarea
    {
        return Textarea::make('purchaser_answers')
            ->label('Purchaser Answers')
            ->default($this->answerSummary($orderItem))
            ->disabled()
            ->dehydrated(false)
            ->rows(min(10, max(2, $orderItem->questionAnswers->count() + 1)))
            ->columnSpanFull();
    }

    /** @return array<int, mixed> */
    private function eventCreationFields(?OrderItem $orderItem = null): array
    {
        return [
            Section::make('Event')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Event Name')
                        ->default($orderItem === null ? 'Private Lesson' : $orderItem->product->name)
                        ->helperText(LocationNameGuidance::HELP_TEXT)
                        ->required()
                        ->maxLength(255),
                    Select::make('calendar_id')
                        ->label('Calendar')
                        ->options(fn (): array => $this->calendarOptions())
                        ->default(fn (): ?int => Calendar::query()->where('slug', Calendar::SLUG_STAFF)->value('id'))
                        ->searchable()
                        ->preload()
                        ->required(),
                    DateTimePicker::make('start_time')
                        ->label('Starts At')
                        ->timezone($this->displayTimezone())
                        ->after('now')
                        ->required(),
                    DateTimePicker::make('end_time')
                        ->label('Ends At')
                        ->timezone($this->displayTimezone())
                        ->after('start_time')
                        ->required(),
                    Select::make('teacher_ids')
                        ->label('Teachers')
                        ->multiple()
                        ->options(fn (): array => User::query()
                            ->whereHas('roles', fn (Builder $query): Builder => $query
                                ->whereIn('name', ['teacher', 'owner', 'super_admin']))
                            ->orderBy('first_name')
                            ->orderBy('last_name')
                            ->get()
                            ->mapWithKeys(fn (User $teacher): array => [$teacher->id => $teacher->fullName])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Textarea::make('description')
                        ->label('Public Description')
                        ->columnSpanFull(),
                    Textarea::make('details')
                        ->label('Lesson Plan (Staff Only)')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, Select> */
    private function attendeeFields(?OrderItem $orderItem = null): array
    {
        $suggestedStudentIds = $orderItem === null
            ? []
            : $orderItem->order->user->students->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        return [
            Select::make('student_ids')
                ->label('Invite Students')
                ->options(fn (): array => PeopleAndGroupsPicker::selectableStudentOptions($suggestedStudentIds))
                ->multiple()
                ->searchable()
                ->preload()
                ->required(),
        ];
    }

    private function eventSelection(): Select
    {
        return Select::make('event_id')
            ->label('Future Standalone Event')
            ->options(fn (): array => $this->eligibleEventOptions())
            ->searchable()
            ->preload()
            ->required();
    }

    /** @return array<int, string> */
    private function calendarOptions(): array
    {
        $user = $this->authenticatedUser();

        return Calendar::query()
            ->where('slug', '!=', Calendar::SLUG_MY)
            ->assignableBy($user)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    private function eligibleEventOptions(): array
    {
        $user = $this->authenticatedUser();

        return Event::query()
            ->whereNull('course_id')
            ->whereNull('cancelled_at')
            ->where('start_time', '>', now())
            ->whereHas('teacherAssignments')
            ->orderBy('start_time')
            ->get()
            ->filter(fn (Event $event): bool => Gate::forUser($user)->allows('update', $event))
            ->mapWithKeys(fn (Event $event): array => [
                $event->id => $event->start_time?->timezone($this->displayTimezone())->format('M j, Y g:i A').' — '.$event->name,
            ])
            ->all();
    }

    private function createEvent(array $data): Event
    {
        $event = Event::query()->create([
            'name' => $data['name'],
            'calendar_id' => $data['calendar_id'],
            'course_id' => null,
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'description' => $data['description'] ?? null,
            'details' => $data['details'] ?? null,
        ]);

        return app(ManageEventTeacherAssignments::class)->assignCustom(
            $event,
            array_map('intval', $data['teacher_ids'] ?? []),
        );
    }

    private function selectedEvent(array $data): Event
    {
        $event = Event::query()
            ->whereNull('course_id')
            ->whereNull('cancelled_at')
            ->where('start_time', '>', now())
            ->whereHas('teacherAssignments')
            ->find($data['event_id'] ?? null);

        if (! $event instanceof Event) {
            throw ValidationException::withMessages([
                'event_id' => 'Select an eligible future standalone event.',
            ]);
        }

        return $event;
    }

    private function addEventAttendees(Event $event, array $data): void
    {
        PeopleAndGroupsPicker::addEventInvitations(
            event: $event,
            userIds: [],
            studentIds: array_map('intval', $data['student_ids'] ?? []),
        );
    }

    /** @param array<string, mixed> $data */
    private function changeWorkflow(OrderItem $orderItem, array $data): void
    {
        $workflow = FulfillmentWorkflow::tryFrom((string) ($data['fulfillment_workflow'] ?? ''));

        if (! in_array($workflow, [FulfillmentWorkflow::Manual, FulfillmentWorkflow::ScheduledEvent], true)) {
            throw ValidationException::withMessages([
                'fulfillment_workflow' => 'Select a configurable fulfillment workflow.',
            ]);
        }

        DB::transaction(function () use ($orderItem, $workflow): void {
            $lockedOrderItem = OrderItem::query()
                ->lockForUpdate()
                ->find($orderItem->id);

            if (! $lockedOrderItem instanceof OrderItem
                || $lockedOrderItem->fulfillment_workflow === FulfillmentWorkflow::Automatic
                || $lockedOrderItem->remainingQuantity() === 0
                || $lockedOrderItem->activeFulfillments()->exists()) {
                throw ValidationException::withMessages([
                    'fulfillment_workflow' => 'Only outstanding items without recorded fulfillment may change workflow.',
                ]);
            }

            $lockedOrderItem->update(['fulfillment_workflow' => $workflow]);
        });
    }

    /** @return list<int> */
    private function unitNumbers(array $data): array
    {
        return array_values(array_map('intval', $data['unit_numbers'] ?? []));
    }

    /** @return list<int> */
    private function studentIds(array $data): array
    {
        return array_values(array_map('intval', $data['student_ids'] ?? []));
    }

    private function unitLabel(OrderItem $orderItem, int $unitNumber): string
    {
        $answers = $orderItem->questionAnswers
            ->where('unit_number', $unitNumber)
            ->map(fn ($answer): string => $answer->question.': '.$answer->formattedAnswer())
            ->implode('; ');

        return 'Unit '.$unitNumber.(filled($answers) ? ' — '.$answers : '');
    }

    private function answerSummary(OrderItem $orderItem): string
    {
        if ($orderItem->questionAnswers->isEmpty()) {
            return 'No purchaser answers.';
        }

        return collect(range(1, $orderItem->quantity))
            ->map(fn (int $unitNumber): string => $this->unitLabel($orderItem, $unitNumber))
            ->implode("\n");
    }

    /** @return array<int, string> */
    private function activeFulfillmentOptions(OrderItem $orderItem): array
    {
        return $orderItem->activeFulfillments()
            ->with('source')
            ->get()
            ->mapWithKeys(function (OrderItemFulfillment $fulfillment): array {
                return [$fulfillment->id => "Unit {$fulfillment->unit_number} — {$fulfillment->sourceLabel()}"];
            })
            ->all();
    }

    /** @param Collection<int, OrderItem> $records */
    private function ensureBulkWorkflow(Collection $records, FulfillmentWorkflow $workflow): void
    {
        if ($records->isEmpty() || $records->contains(
            fn (OrderItem $record): bool => $record->fulfillment_workflow !== $workflow
                || $record->remainingQuantity() === 0,
        )) {
            throw ValidationException::withMessages([
                'records' => 'Select only outstanding items that use the '.$workflow->getLabel().' workflow.',
            ]);
        }
    }

    private function success(string $title, ?string $url = null): void
    {
        $notification = Notification::make()->title($title)->success();

        if ($url !== null) {
            $notification
                ->body('Open the linked event to review its details.')
                ->actions([
                    Action::make('viewEvent')->label('View Event')->url($url),
                ]);
        }

        $notification->send();
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('An authenticated user is required.');
        }

        return $user;
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
