<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Actions\Store\AddToCart;
use App\Contracts\HasCapacity;
use App\Filament\Actions\CancelEventAction;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use App\Filament\User\Pages\ProductDetails;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Product;
use App\Models\User;
use App\Services\DashboardScheduleService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Saade\FilamentFullCalendar\Actions\CreateAction;
use Saade\FilamentFullCalendar\Actions\EditAction;
use Saade\FilamentFullCalendar\Actions\ViewAction;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

final class CalendarWidget extends FullCalendarWidget
{
    use HasRecurring;

    public Model|string|null $model = Event::class;

    public ?int $selectedCalendarId = null;

    protected int|string|array $columnSpan = 'full';

    public function mount(): void
    {
        $this->selectedCalendarId ??= $this->selectedCalendar()?->id;
    }

    public function config(): array
    {
        return [
            'firstDay' => 0,
            'fixedWeekCount' => false,
            'headerToolbar' => [
                'left' => 'listMonth,dayGridMonth,dayGridWeek,dayGridDay',
                'center' => 'title',
                'right' => 'prev,next today',
            ],
        ];
    }

    public function eventDidMount(): string
    {
        return <<<'JS'
            function ({ el, event }) {
                el.style.cursor = event.extendedProps.isHoliday ? 'default' : 'pointer'
            }
        JS;
    }

    public function getFormSchema(): array
    {
        if ($this->isAdminPanel()) {
            return [
                ...EventForm::components(),
                $this->cancellationSection(),
            ];
        }

        return [
            Section::make('Event')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Event'),
                    TextInput::make('calendar_name')
                        ->label('Calendar'),
                    TextInput::make('course_name')
                        ->label('Course'),
                    TextInput::make('focus')
                        ->label('Focus / Theme'),
                    DateTimePicker::make('start_time')
                        ->label('Starts At')
                        ->timezone($this->displayTimezone()),
                    DateTimePicker::make('end_time')
                        ->label('Ends At')
                        ->timezone($this->displayTimezone()),
                    Textarea::make('description')
                        ->label('Description')
                        ->columnSpanFull(),
                ]),
            $this->cancellationSection(),
        ];
    }

    public function fetchEvents(array $fetchInfo): array
    {
        $calendar = $this->selectedCalendar();
        $user = auth()->user();

        if (! $calendar instanceof Calendar || ! $user instanceof User) {
            return [];
        }

        $startsAt = Carbon::parse($fetchInfo['start']);
        $endsAt = Carbon::parse($fetchInfo['end']);

        return app(DashboardScheduleService::class)->fullCalendarEvents($user, $calendar, $startsAt, $endsAt);
    }

    public function selectCalendar(int $calendarId): void
    {
        $calendar = $this->accessibleCalendars()->firstWhere('id', $calendarId);

        if (! $calendar instanceof Calendar) {
            return;
        }

        $this->selectedCalendarId = $calendar->id;
        $this->refreshRecords();
        $this->dispatch('$refresh');
    }

    public function onEventClick(array $event): void
    {
        if (($event['extendedProps']['isHoliday'] ?? false) === true) {
            return;
        }

        if ($this->isAdminPanel()) {
            parent::onEventClick($event);

            return;
        }

        if (! $this->canViewEvent((int) ($event['id'] ?? 0))) {
            return;
        }

        parent::onEventClick($event);
    }

    protected function headerActions(): array
    {
        $calendars = $this->accessibleCalendars()
            ->map(function (Calendar $calendar): Action {
                $actionName = 'calendar_'.$calendar->id;

                return Action::make($actionName)
                    ->label($calendar->name)
                    ->alpineClickHandler('close(); $wire.mountAction(\''.$actionName.'\')')
                    ->action(function () use ($calendar): void {
                        $this->selectCalendar($calendar->id);
                    });
            })
            ->all();

        return [
            ...($this->isAdminPanel() ? [
                CreateAction::make()
                    ->authorize('create')
                    ->mutateDataUsing(fn (array $data): array => $this->prepRecurringData($data))
                    ->after(function (array $data, CreateAction $action): void {
                        $this->createRecurring($data, $this->repeat_through, $this->repeat_frequency, function (array $data) use ($action): void {
                            $model = $action->getModel();
                            $record = new $model($data);
                            $record->save();
                        });
                        $this->refreshRecords();
                    }),
            ] : []),
            ActionGroup::make($calendars)
                ->label(fn (): string => $this->selectedCalendar()->name ?? 'Calendar')
                ->button()
                ->icon(Heroicon::OutlinedCalendar),
        ];
    }

    protected function modalActions(): array
    {
        $cancelEventAction = CancelEventAction::make()
            ->after(function (): void {
                $this->refreshRecords();
                $this->dispatch('$refresh');
            });

        if (! $this->isAdminPanel()) {
            return [
                Action::make('addCourseProductToCart')
                    ->label('Add to Cart')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->color('primary')
                    ->visible(function (): bool {
                        $product = $this->courseEventProduct();

                        return $product instanceof Product
                            && ! $product->hasPurchaserQuestions();
                    })
                    ->disabled(fn (): bool => $this->courseEventProductIsSoldOut())
                    ->action(function (): void {
                        $this->addCourseEventProductToCart();
                    }),
                Action::make('viewCourseProductInStore')
                    ->label('View in Store')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (): bool => $this->courseEventProduct() instanceof Product)
                    ->url(fn (): ?string => ($product = $this->courseEventProduct()) instanceof Product
                        ? ProductDetails::getUrl(['product' => $product])
                        : null),
                $cancelEventAction,
            ];
        }

        return [
            EditAction::make()
                ->authorize('update')
                ->visible(fn (Event $record): bool => ! $record->isCancelled()),
            $cancelEventAction,
            Action::make('viewFullEvent')
                ->label('View Full Event')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => $this->canViewFullEvent())
                ->url(fn (): ?string => $this->fullEventUrl()),
        ];
    }

    protected function viewAction(): Action
    {
        $action = ViewAction::make()
            ->slideOver(false)
            ->modalWidth('lg')
            ->modalHeading(fn (Event $record): string => $record->name);

        if ($this->isAdminPanel()) {
            return $action->mutateRecordDataUsing(function (array $data, Event $record): array {
                if (! $this->canViewPrivateEventContent($record)) {
                    unset($data['details']);
                }

                return $data;
            });
        }

        return $action->mutateRecordDataUsing(function (array $data, Event $record): array {
            unset($data['details']);

            return [
                ...$data,
                'calendar_name' => $record->calendar?->name,
                'course_name' => $record->course?->name,
            ];
        });
    }

    private function selectedCalendar(): ?Calendar
    {
        $calendars = $this->accessibleCalendars();

        $calendar = $calendars->firstWhere('id', $this->selectedCalendarId);

        if ($calendar instanceof Calendar) {
            return $calendar;
        }

        $fallback = $calendars->firstWhere('slug', Calendar::SLUG_EAC)
            ?? $calendars->firstWhere('slug', Calendar::SLUG_MY)
            ?? $calendars->first();
        $this->selectedCalendarId = $fallback?->id;

        return $fallback;
    }

    /**
     * @return EloquentCollection<int, Calendar>
     */
    private function accessibleCalendars(): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new EloquentCollection();
        }

        return app(DashboardScheduleService::class)->accessibleCalendars($user);
    }

    private function canViewEvent(int $eventId): bool
    {
        $calendar = $this->selectedCalendar();
        $user = auth()->user();

        if ($eventId < 1 || ! $calendar instanceof Calendar || ! $user instanceof User) {
            return false;
        }

        return Event::query()
            ->whereKey($eventId)
            ->visibleOnCalendar($calendar, $user)
            ->exists();
    }

    private function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }

    private function courseEventProduct(): ?Product
    {
        $record = $this->getRecord();

        if (! $record instanceof Event || $record->isCancelled()) {
            return null;
        }

        $record->loadMissing('course.product');

        $product = $record->course?->getRelation('product');
        $user = auth()->user();

        if (! $product instanceof Product || ! $user instanceof User || ! $product->canBePurchasedBy($user)) {
            return null;
        }

        return $product;
    }

    private function courseEventProductIsSoldOut(): bool
    {
        $product = $this->courseEventProduct();

        return $product?->productable instanceof HasCapacity
            && $product->productable->getAvailableCapacity() <= 0;
    }

    private function addCourseEventProductToCart(): void
    {
        $product = $this->courseEventProduct();
        $user = auth()->user();

        if (! $product instanceof Product || ! $user instanceof User) {
            return;
        }

        try {
            app(AddToCart::class)->handle($user, $product);

            $this->dispatch('refresh-sidebar');

            Notification::make()
                ->title('Added to cart')
                ->body("\"{$product->name}\" has been added to your cart.")
                ->success()
                ->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Could not add to cart')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private function canViewFullEvent(): bool
    {
        $record = $this->getRecord();

        return $record instanceof Event && EventResource::canView($record);
    }

    private function canViewPrivateEventContent(Event $event): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('view', $event);
    }

    private function fullEventUrl(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof Event || ! EventResource::canView($record)) {
            return null;
        }

        return EventResource::getUrl(name: 'view', parameters: ['record' => $record]);
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }

    private function cancellationSection(): Section
    {
        return Section::make('Cancellation')
            ->visible(fn (?Event $record): bool => $record instanceof Event && $record->isCancelled())
            ->columns(2)
            ->schema([
                DateTimePicker::make('cancelled_at')
                    ->label('Cancelled At')
                    ->timezone($this->displayTimezone()),
                Textarea::make('cancellation_reason')
                    ->label('Reason')
                    ->columnSpanFull(),
            ]);
    }
}
