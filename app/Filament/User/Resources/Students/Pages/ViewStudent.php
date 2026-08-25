<?php

declare(strict_types=1);

namespace App\Filament\User\Resources\Students\Pages;

use App\Actions\Students\UpdateStudentContactDetails;
use App\Filament\Shared\Schemas\ProgressiveList;
use App\Filament\Shared\Schemas\StudentContactForm;
use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\Students\StudentResource;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use App\Support\EnrollmentStatus;
use App\Support\Filament\CourseStaffPresenter;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * @property-read Schema $contactForm
 */
final class ViewStudent extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    /**
     * @var array<string, mixed>
     */
    public array $contactData = [];

    public int $historyLimit = 5;

    public bool $automaticHistoryLoading = false;

    protected static string $resource = StudentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->contactForm->fill($this->contactFormData());
    }

    public function contactForm(Schema $schema): Schema
    {
        return StudentContactForm::configure(
            $schema,
            Action::make('saveContactDetails')
                ->label('Save Student Details')
                ->submit('saveContactDetails'),
        )
            ->model($this->student())
            ->statePath('contactData');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    EmbeddedSchema::make('contactForm'),
                ])
                    ->id('contact-form')
                    ->livewireSubmitHandler('saveContactDetails'),
                $this->medicalWaiverSection(),
                Section::make('Courses / Events')
                    ->columnSpanFull()
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
                Section::make('Course History')
                    ->columnSpanFull()
                    ->schema([
                        ProgressiveList::make(
                            items: fn (): array => $this->historyItems(),
                            hasMore: fn (): bool => $this->hasMoreHistory(),
                            automaticLoading: fn (): bool => $this->automaticHistoryLoading,
                            loadMethod: 'loadMoreHistory',
                            itemView: 'filament.shared.enrollment-list-item',
                            emptyMessage: 'No past classes.',
                            batchSize: 5,
                        ),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->studentEventRecords())
            ->reorderableColumns(false)
            ->recordTitle(fn (Event $record): string => $record->name)
            ->columns([
                TextColumn::make('event_summary')
                    ->label('Event')
                    ->state(fn (Event $record): string => $this->studentEventSummary($record)),
                TextColumn::make('teacher')
                    ->state(fn (Event $record): ?HtmlString => CourseStaffPresenter::render($record->course))
                    ->searchable(false)
                    ->sortable(false)
                    ->placeholder('-'),
                TextColumn::make('start_time')
                    ->label('Next Meeting Time')
                    ->dateTime()
                    ->searchable(false)
                    ->sortable(false)
                    ->placeholder('-'),
            ])
            ->recordAction('viewStudentEventDetails')
            ->recordActions([
                $this->viewStudentEventDetailsAction(),
            ])
            ->paginated(false)
            ->searchable(false)
            ->emptyStateHeading('No events')
            ->emptyStateDescription('Assigned classes and direct invitations will appear here.');
    }

    public function saveContactDetails(): void
    {
        $data = $this->contactForm->getState();

        /** @var User $user */
        $user = auth()->user();

        try {
            app(UpdateStudentContactDetails::class)->handle(
                student: $this->student(),
                user: $user,
                nickname: $data['nickname'] ?? null,
                additionalEmails: $data['additional_emails'] ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError('contactData.additional_emails', $exception->getMessage());

            return;
        }

        $this->student()->refresh();
        $this->contactForm->fill($this->contactFormData());

        Notification::make()
            ->title('Student details saved')
            ->success()
            ->send();
    }

    public function loadMoreHistory(int $batchSize = 5): void
    {
        $this->historyLimit += max(1, min($batchSize, 100));
        $this->automaticHistoryLoading = true;
    }

    private function medicalWaiverSection(): Section
    {
        return Section::make('Medical Waiver')
            ->columns(2)
            ->columnSpanFull()
            ->schema([
                TextEntry::make('medical_waiver_status')
                    ->label('Form Status')
                    ->state(fn () => $this->student()->medicalWaiverStatus())
                    ->badge(),
                TextEntry::make('medical_waiver_updated_at')
                    ->label('Last Updated')
                    ->state(fn () => $this->student()->currentMedicalWaiver()?->updated_at)
                    ->dateTime()
                    ->placeholder('Never'),
                Actions::make([
                    Action::make('viewMedicalWaiver')
                        ->label('View current medical waiver')
                        ->url(fn (): ?string => $this->student()->currentMedicalWaiver() === null
                            ? null
                            : FormUserResource::getUrl('view', ['record' => $this->student()->currentMedicalWaiver()]))
                        ->visible(fn (): bool => $this->student()->currentMedicalWaiver() !== null),
                    Action::make('completeMedicalWaiver')
                        ->label('Complete Medical Waiver')
                        ->url(fn (): ?string => $this->student()->pendingMedicalWaiver() === null
                            ? null
                            : FormUserResource::getUrl('edit', ['record' => $this->student()->pendingMedicalWaiver()]))
                        ->visible(fn (): bool => $this->student()->currentMedicalWaiver() === null
                            && $this->student()->pendingMedicalWaiver() !== null),
                    Action::make('updateMedicalWaiver')
                        ->label('Update')
                        ->url(fn (): ?string => $this->student()->latestValidCompletedMedicalWaiver() === null
                            ? null
                            : FormUserResource::getUrl('revise', ['record' => $this->student()->latestValidCompletedMedicalWaiver()]))
                        ->visible(fn (): bool => $this->student()->latestValidCompletedMedicalWaiver()?->formCanBeUpdated() ?? false),
                ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contactFormData(): array
    {
        $student = $this->student()->load('additionalEmails');

        return [
            'nickname' => $student->nickname,
            'additional_emails' => $student->additionalEmails
                ->map(fn (StudentEmail $studentEmail): array => [
                    'id' => $studentEmail->id,
                    'email' => $studentEmail->email,
                    'relationship_option' => in_array($studentEmail->relationship, ['Mother', 'Father', 'Dancer'], true)
                        ? $studentEmail->relationship
                        : 'Other',
                    'relationship' => $studentEmail->relationship,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function historyItems(): array
    {
        return $this->historyEnrollments()
            ->take($this->historyLimit)
            ->map(fn (Enrollment $enrollment): array => $this->enrollmentItem($enrollment))
            ->values()
            ->all();
    }

    private function hasMoreHistory(): bool
    {
        return $this->historyEnrollments()->count() > $this->historyLimit;
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function historyEnrollments(): Collection
    {
        return $this->student()
            ->enrollments()
            ->past()
            ->with(['course.events', 'course.teachers.media'])
            ->select('enrollments.*')
            ->orderByDesc(DB::raw(
                '(SELECT MAX(COALESCE(events.end_time, events.start_time)) FROM events WHERE events.course_id = enrollments.course_id)'
            ))
            ->limit($this->historyLimit + 1)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentItem(Enrollment $enrollment): array
    {
        $course = $enrollment->course;
        $status = EnrollmentStatus::for($enrollment);

        return [
            'course' => $course->name,
            'semester' => $course->semester->getLabel(),
            'teacher' => CourseStaffPresenter::render($course),
            'status' => $status,
            'starts_at' => $this->enrollmentMeetingTime($enrollment)
                ?->timezone((string) config('app.display_timezone', config('app.timezone')))
                ->format('M j, Y g:i A'),
        ];
    }

    private function enrollmentMeetingTime(Enrollment $enrollment): ?CarbonInterface
    {
        $course = $enrollment->course;

        if ($course === null) {
            return null;
        }

        if (EnrollmentStatus::for($enrollment) === 'Past') {
            return $course->lastMeetingEndsAt();
        }

        return $course->nextMeetingStartsAt();
    }

    /** @return Builder<Event> */
    private function studentEventsQuery(): Builder
    {
        $student = $this->student();
        $studentMorphClass = $student->getMorphClass();

        return Event::query()
            ->notPassed()
            ->whereDoesntHave(
                'excludedUsers',
                fn (Builder $query): Builder => $query->whereKey(auth()->id())
            )
            ->where(function (Builder $query) use ($student, $studentMorphClass): void {
                $query
                    ->whereHas('course.enrollments', fn (Builder $query): Builder => $query
                        ->where('student_id', $student->id)
                        ->where('user_id', auth()->id()))
                    ->orWhereHas('attendees', fn (Builder $query): Builder => $query
                        ->where('attendee_type', $studentMorphClass)
                        ->where('attendee_id', $student->id));
            })
            ->with(['calendar', 'course.teachers.media']);
    }

    /**
     * @return Collection<int, Event>
     */
    private function studentEventRecords(): Collection
    {
        return $this->studentEventsQuery()
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Event $event): string => $this->studentEventGroupingKey($event))
            ->map(function (Collection $events): Event {
                /** @var Event $nextEvent */
                $nextEvent = $events->first();

                $nextEvent->setAttribute('additional_recurring_events_count', $events->count() - 1);

                return $nextEvent;
            })
            ->sortBy(fn (Event $event): mixed => $event->start_time)
            ->values();
    }

    private function studentEventGroupingKey(Event $event): string
    {
        if ($event->course_id === null || ! $event->start_time instanceof CarbonInterface) {
            return "event:{$event->id}";
        }

        $localStartTime = $event->start_time
            ->copy()
            ->timezone((string) config('app.display_timezone', config('app.timezone')));
        $durationInSeconds = $event->end_time instanceof CarbonInterface
            ? $event->start_time->diffInSeconds($event->end_time, true)
            : null;

        return json_encode([
            'course_id' => $event->course_id,
            'name' => $event->name,
            'weekday' => $localStartTime->dayOfWeek,
            'time' => $localStartTime->format('H:i:s'),
            'duration_in_seconds' => $durationInSeconds,
        ], JSON_THROW_ON_ERROR);
    }

    private function studentEventSummary(Event $event): string
    {
        $additionalEventsCount = (int) $event->getAttribute('additional_recurring_events_count');

        if ($additionalEventsCount === 0 || ! $event->start_time instanceof CarbonInterface) {
            return $event->name;
        }

        $weekday = $event->start_time
            ->copy()
            ->timezone((string) config('app.display_timezone', config('app.timezone')))
            ->format('l');

        return "{$event->name} - ".Str::plural($weekday, $additionalEventsCount).", ({$additionalEventsCount} more)";
    }

    private function viewStudentEventDetailsAction(): ViewAction
    {
        return ViewAction::make('viewStudentEventDetails')
            ->authorize(true)
            ->label('View Details')
            ->modalHeading(fn (Event $record): string => $record->name)
            ->modalWidth('lg')
            ->slideOver(false)
            ->schema([
                Section::make('Event')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Event'),
                        TextInput::make('calendar_name')
                            ->label('Calendar'),
                        TextInput::make('course_name')
                            ->label('Course'),
                        TextEntry::make('teacher')
                            ->formatStateUsing(fn (Event $record): ?HtmlString => CourseStaffPresenter::render($record->course))
                            ->placeholder('-'),
                        DateTimePicker::make('start_time')
                            ->label('Starts At'),
                        DateTimePicker::make('end_time')
                            ->label('Ends At'),
                        TextInput::make('focus')
                            ->label('Focus / Theme'),
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),
            ])
            ->fillForm(fn (Event $record): array => [
                'name' => $record->name,
                'calendar_name' => $record->calendar?->name,
                'course_name' => $record->course?->name,
                'teacher' => $record->course?->teacherDisplayName,
                'start_time' => $record->start_time,
                'end_time' => $record->end_time,
                'focus' => $record->focus,
                'description' => $record->description,
            ]);
    }

    private function student(): Student
    {
        $student = $this->getRecord();

        if (! $student instanceof Student) {
            throw new LogicException('Student view pages require a student record.');
        }

        return $student;
    }
}
