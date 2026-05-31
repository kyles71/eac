<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Saade\FilamentFullCalendar\Actions\CreateAction;
use Saade\FilamentFullCalendar\Actions\DeleteAction;
use Saade\FilamentFullCalendar\Actions\EditAction;
use Saade\FilamentFullCalendar\Actions\ViewAction;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

final class CalendarWidget extends FullCalendarWidget
{
    use HasRecurring;

    public Model|string|null $model = Event::class;

    public ?int $selectedCalendarId = null;

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
            function ({ el }) {
                el.style.cursor = 'pointer'
            }
        JS;
    }

    public function getFormSchema(): array
    {
        if ($this->isAdminPanel()) {
            return EventForm::components();
        }

        return [
            TextInput::make('name')
                ->label('Event'),
            TextInput::make('calendar_name')
                ->label('Calendar'),
            TextInput::make('course_name')
                ->label('Course'),
            DateTimePicker::make('start_time'),
            DateTimePicker::make('end_time'),
            TextInput::make('focus'),
            Textarea::make('description')
                ->columnSpanFull(),
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
        $accessibleCalendars = $this->accessibleCalendars();

        return Event::query()
            ->with(['calendar', 'course.tags'])
            ->overlapping($startsAt, $endsAt)
            ->visibleOnCalendar($calendar, $user)
            ->orderBy('events.start_time')
            ->get()
            ->map(
                function (Event $event) use ($accessibleCalendars, $calendar): array {
                    $displayCalendar = $this->displayCalendarForEvent($event, $calendar, $accessibleCalendars);

                    return [
                        'id' => $event->id,
                        'title' => $event->name,
                        'start' => $this->calendarTimestamp($event->start_time),
                        'end' => $this->calendarTimestamp($event->end_time),
                        'backgroundColor' => $displayCalendar?->background_color,
                        'borderColor' => $displayCalendar?->background_color,
                        ...($this->isAdminPanel() ? [
                            'url' => EventResource::getUrl(name: 'view', parameters: ['record' => $event]),
                            'shouldOpenUrlInNewTab' => false,
                        ] : []),
                    ];
                }
            )
            ->toArray();
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
                ->label(fn (): string => $this->selectedCalendar()?->name ?? 'Calendar')
                ->button()
                ->icon(Heroicon::OutlinedCalendar),
        ];
    }

    protected function modalActions(): array
    {
        if (! $this->isAdminPanel()) {
            return [];
        }

        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function viewAction(): Action
    {
        $action = ViewAction::make();

        if ($this->isAdminPanel()) {
            return $action;
        }

        return $action->mutateRecordDataUsing(fn (array $data, Event $record): array => [
            ...$data,
            'calendar_name' => $record->calendar?->name,
            'course_name' => $record->course?->name,
        ]);
    }

    private function selectedCalendar(): ?Calendar
    {
        $calendars = $this->accessibleCalendars();

        $calendar = $calendars->firstWhere('id', $this->selectedCalendarId);

        if ($calendar instanceof Calendar) {
            return $calendar;
        }

        $fallback = $calendars->firstWhere('slug', Calendar::SLUG_MY) ?? $calendars->first();
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

        return Calendar::query()
            ->with('tags')
            ->visibleTo($user)
            ->orderBy('id')
            ->get();
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

    /**
     * @param  EloquentCollection<int, Calendar>  $accessibleCalendars
     */
    private function displayCalendarForEvent(Event $event, Calendar $selectedCalendar, EloquentCollection $accessibleCalendars): ?Calendar
    {
        if (! $selectedCalendar->isMyCalendar()) {
            return $selectedCalendar;
        }

        if ($event->course instanceof Course) {
            $courseCalendarSlugs = $event->course
                ->tags
                ->where('type', Course::CALENDAR_TAG_TYPE)
                ->pluck('name');

            $routedCalendar = $accessibleCalendars
                ->where('slug', '!=', Calendar::SLUG_MY)
                ->first(fn (Calendar $calendar): bool => $courseCalendarSlugs->contains($calendar->slug));

            if ($routedCalendar instanceof Calendar) {
                return $routedCalendar;
            }
        }

        return $event->calendar;
    }

    private function calendarTimestamp(?CarbonInterface $dateTime): ?string
    {
        return $dateTime?->copy()
        ->toIso8601String();
    }

    private function isAdminPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin';
    }
}
