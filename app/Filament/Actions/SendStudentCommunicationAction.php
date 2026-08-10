<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Students\SendStudentCommunication;
use App\Enums\StopLightColor;
use App\Enums\StudentCommunicationType;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use App\Services\StudentCommunicationEventService;
use Carbon\CarbonInterface;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

final class SendStudentCommunicationAction extends BaseEmailAction
{
    protected Student|Closure|null $student = null;

    protected Event|Closure|null $defaultEvent = null;

    protected ?StudentCommunicationType $communicationType = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(fn (): string => match ($this->getCommunicationType()) {
                StudentCommunicationType::FirstAid => 'First Aid Note',
                StudentCommunicationType::StopLight => 'Stop Light Message',
            })
            ->icon(fn (): Heroicon => match ($this->getCommunicationType()) {
                StudentCommunicationType::FirstAid => Heroicon::OutlinedPlusCircle,
                StudentCommunicationType::StopLight => Heroicon::OutlinedExclamationTriangle,
            })
            ->authorize(fn (): bool => Gate::allows('Send:Email')
                && Gate::allows('create', StudentCommunication::class))
            ->schema(fn (): array => [
                $this->recipientSelect(),
                DateTimePicker::make('occurred_at')
                    ->label('Date and Time')
                    ->default(fn (): CarbonInterface => $this->defaultOccurredAt())
                    ->seconds(false)
                    ->timezone($this->displayTimezone())
                    ->required(),
                Select::make('event_id')
                    ->label('Event')
                    ->options(fn (): array => $this->eventOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->eventOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $this->eventLabel($value))
                    ->default(fn (): ?int => $this->getFixedEvent()?->id)
                    ->disabled(fn (): bool => $this->getFixedEvent() instanceof Event)
                    ->dehydrated()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $event = $this->findEvent($state);

                        if ($event instanceof Event) {
                            $set('occurred_at', $this->occurredAtForEvent($event));
                        }
                    }),
                Select::make('stop_light_color')
                    ->label('Stop Light Color')
                    ->options(StopLightColor::class)
                    ->required(fn (): bool => $this->getCommunicationType() === StudentCommunicationType::StopLight)
                    ->visible(fn (): bool => $this->getCommunicationType() === StudentCommunicationType::StopLight),
                Textarea::make('note')
                    ->label('Note')
                    ->rows(6)
                    ->required()
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                $recipients = $this->resolveRecipients($data['to'] ?? []);

                if ($recipients === []) {
                    throw ValidationException::withMessages([
                        'to' => 'At least one valid email recipient is required.',
                    ]);
                }

                $communication = app(SendStudentCommunication::class)->handle(
                    student: $this->getStudent(),
                    author: $this->getAuthenticatedUser(),
                    type: $this->getCommunicationType(),
                    occurredAt: (string) ($data['occurred_at'] ?? ''),
                    event: $this->getFixedEvent() ?? $this->findEvent($data['event_id'] ?? null, fail: true),
                    stopLightColor: $this->normalizeStopLightColor($data['stop_light_color'] ?? null),
                    note: (string) ($data['note'] ?? ''),
                    recipientEmails: $recipients,
                );

                $notification = Notification::make()
                    ->title($communication instanceof StudentCommunication
                        ? 'Communication queued'
                        : 'Communication email template is disabled');

                ($communication instanceof StudentCommunication
                    ? $notification->success()
                    : $notification->warning())->send();
            });
    }

    public static function getDefaultName(): string
    {
        return 'sendStudentCommunication';
    }

    public function student(Student|Closure $student): static
    {
        $this->student = $student;
        $this->to($student);

        return $this;
    }

    public function event(Event|Closure|null $event): static
    {
        $this->defaultEvent = $event;

        return $this;
    }

    public function communicationType(StudentCommunicationType $type): static
    {
        $this->communicationType = $type;

        return $this;
    }

    private function normalizeStopLightColor(mixed $color): ?StopLightColor
    {
        if ($color instanceof StopLightColor) {
            return $color;
        }

        return is_string($color) ? StopLightColor::tryFrom($color) : null;
    }

    private function getStudent(): Student
    {
        $student = $this->evaluate($this->student);

        if (! $student instanceof Student) {
            throw new LogicException('The student record is unavailable.');
        }

        return $student;
    }

    private function getFixedEvent(): ?Event
    {
        $event = $this->evaluate($this->defaultEvent);

        return $event instanceof Event ? $event : null;
    }

    private function getCommunicationType(): StudentCommunicationType
    {
        return $this->communicationType
            ?? throw new LogicException('A student communication type is required.');
    }

    private function getAuthenticatedUser(): User
    {
        return $this->authenticatedUser()
            ?? throw new LogicException('Student communications require an authenticated user.');
    }

    /**
     * @return array<int, string>
     */
    private function eventOptions(?string $search = null): array
    {
        $fixedEvent = $this->getFixedEvent();

        if ($fixedEvent instanceof Event) {
            return [$fixedEvent->id => $this->events()->label($fixedEvent)];
        }

        return $this->events()->options(
            $this->getStudent(),
            $this->getAuthenticatedUser(),
            $search,
        );
    }

    private function eventLabel(mixed $eventId): ?string
    {
        $event = $this->findEvent($eventId);

        return $event instanceof Event ? $this->events()->label($event) : null;
    }

    private function findEvent(mixed $eventId, bool $fail = false): ?Event
    {
        if (blank($eventId)) {
            return null;
        }

        return $fail
            ? $this->events()->findOrFail($this->getStudent(), $this->getAuthenticatedUser(), (int) $eventId)
            : $this->events()->find($this->getStudent(), $this->getAuthenticatedUser(), (int) $eventId);
    }

    private function defaultOccurredAt(): CarbonInterface
    {
        $event = $this->getFixedEvent();

        return $event instanceof Event
            ? $this->occurredAtForEvent($event)
            : now();
    }

    private function occurredAtForEvent(Event $event): CarbonInterface
    {
        return ($event->end_time ?? $event->start_time ?? now())
            ->copy();
    }

    private function events(): StudentCommunicationEventService
    {
        return app(StudentCommunicationEventService::class);
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
