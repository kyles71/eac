<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Traits\HasRecurring;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Saade\FilamentFullCalendar\Actions\CreateAction;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

final class CalendarWidget extends FullCalendarWidget
{
    use HasRecurring;

    public Model|string|null $model = Event::class;

    public Calendar $calendar;

    public Collection $calendars;

    public function mount(): void
    {
        $this->calendars = Calendar::get();
        $this->calendar = $this->calendars->first();
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

    public function getFormSchema(): array
    {
        return EventForm::components();
    }

    public function fetchEvents(array $fetchInfo): array
    {
        return Event::query()
            ->select('events.*')
            ->where('events.start_time', '>=', $fetchInfo['start'])
            ->where('events.end_time', '<=', $fetchInfo['end'])
            ->whereNotNull('events.calendar_id')
            ->when(
                $this->calendar?->id > 2,
                fn ($query) => $query->where('events.calendar_id', $this->calendar->id)
            )
            ->when($this->calendar->id === 1,
                fn ($query) => $query->join('courses', 'events.course_id', '=', 'courses.id')
                    ->where('courses.teacher_id', auth()->id())
                    ->union(
                        User::query()
                            ->select('events.*')
                            ->leftJoin('students', 'users.id', '=', 'students.user_id')
                            ->join('event_attendees', function ($join): void {
                                $join->on(function ($q): void {
                                    $q->on('students.id', '=', 'event_attendees.attendee_id')
                                        ->where('event_attendees.attendee_type', Student::class);
                                })
                                    ->orOn(function ($q): void {
                                        $q->on('users.id', '=', 'event_attendees.attendee_id')
                                            ->where('event_attendees.attendee_type', User::class);
                                    });
                            })
                            ->join('events', 'event_attendees.event_id', '=', 'events.id')
                            ->where('users.id', auth()->id())
                    )
            )
            ->get()
            ->map(
                fn (Event $event): array => [
                    'title' => $event->name,
                    'start' => $event->start_time,
                    'end' => $event->end_time,
                    'backgroundColor' => $event->calendar->background_color,
                    'borderColor' => $event->calendar->background_color,
                    // 'url' => EventResource::getUrl(name: 'view', parameters: ['record' => $event]),
                    // 'shouldOpenUrlInNewTab' => true
                ]
            )
            ->toArray();
    }

    public function onEventClick(array $event): void
    {
        // do nothing
    }

    protected function headerActions(): array
    {
        $calendars = $this->calendars
            ->map(fn($calendar): Action => Action::make('calendar_'.$calendar->id)
                ->label($calendar->name)
                ->extraAttributes(['x-on:click' => 'close'])
                ->action(function () use ($calendar): void {
                    // validate calendar id - maybe use livewire validation?
                    $this->calendar = $calendar;
                    $this->refreshRecords();
                }))
            ->all();

        return [
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
            ActionGroup::make($calendars)
                ->label(fn() => $this->calendar->name)
                ->button()
                ->icon(false),
        ];
    }
}
