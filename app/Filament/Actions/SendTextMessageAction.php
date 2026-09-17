<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\TextMessages\QueueEventTextMessages;
use App\Models\Event;
use App\Models\User;
use App\Services\TextMessages\EventTextRecipients;
use App\Services\TextMessages\TextMessageReview;
use App\Support\TextMessages\MessageLength;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SendTextMessageAction extends Action
{
    protected Event|Closure|null $textEvent = null;

    protected bool $targetsDate = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Send Text Message')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->authorize(fn (): bool => $this->canSend())
            ->disabled(fn (): bool => ! config('text-messages.enabled'))
            ->tooltip(fn (): ?string => config('text-messages.enabled') ? null : 'Text messaging is currently disabled.')
            ->slideOver(false)
            ->stickyModalHeader(false)
            ->stickyModalFooter(false)
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Send Text Message')
            ->fillForm(function (): array {
                $now = now()->timezone(config('app.display_timezone'));

                return [
                    'date' => $now->format('Y-m-d'),
                    'time' => $now->format('H:i'),
                    'event_ids' => $this->targetsDate
                        ? array_keys($this->dateOptions($now->format('Y-m-d'), $now->format('H:i')))
                        : [$this->event()?->id],
                    'submission_token' => (string) Str::uuid(),
                    'body' => '',
                    'review_token' => null,
                ];
            })
            ->steps(fn (): array => [
                Step::make('Compose')
                    ->schema([
                        Hidden::make('submission_token')->required(),
                        DatePicker::make('date')->label('Date')->required()->live()
                            ->visible($this->targetsDate)
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshDateSelection($get, $set)),
                        /** The cutoff is a wall-clock value; EventTextRecipients converts it together with the selected date. */
                        TimePicker::make('time')->label('Events starting at or after')->format('H:i')->seconds(false)
                            ->timezone(config('app.timezone'))
                            ->required()->live()->visible($this->targetsDate)
                            ->helperText('Times are shown in '.config('app.display_timezone').'.')
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->refreshDateSelection($get, $set)),
                        Select::make('event_ids')->label('Events')->multiple()->required()
                            ->visible($this->targetsDate)
                            ->options(fn (Get $get): array => $this->dateOptions((string) $get('date'), (string) $get('time')))
                            ->helperText('Includes cancelled events. Remove any events that should not receive this message.'),
                        Textarea::make('body')->label('Message')->required()->rows(5)->maxLength(918)
                            ->live(onBlur: true)
                            ->helperText(function (?string $state): string {
                                $length = MessageLength::measure($state ?? '');

                                return "{$length['characters']} characters · {$length['parts']} of 6 SMS parts. Include EAC in your message so families know who is contacting them.";
                            }),
                    ])
                    ->afterValidation(function (Get $get, Set $set): void {
                        $ids = $this->targetsDate ? (array) $get('event_ids') : [$this->event()?->id];
                        $selection = $this->targetsDate ? ['date' => (string) $get('date'), 'time' => (string) $get('time')] : null;
                        $set('review_token', $this->withValidationNotice(fn (): string => app(TextMessageReview::class)->prepare(
                            $this->author(), $ids, (string) $get('body'), (string) $get('submission_token'), $selection,
                        )));
                    }),
                Step::make('Review')
                    ->schema([
                        Hidden::make('review_token')->required(),
                        View::make('filament.admin.pages.partials.text-message-review')
                            ->viewData(fn (Get $get): array => [
                                'review' => filled($get('review_token'))
                                    ? app(TextMessageReview::class)->read($this->author(), (string) $get('review_token'))
                                    : null,
                            ]),
                    ]),
            ])
            ->action(fn (array $data) => $this->withValidationNotice(function () use ($data): void {
                $reviewToken = (string) ($data['review_token'] ?? '');
                $review = app(TextMessageReview::class)->read($this->author(), $reviewToken);

                if ($review['body'] !== ($data['body'] ?? '') || $review['submission_token'] !== ($data['submission_token'] ?? '')) {
                    throw ValidationException::withMessages(['body' => 'The message has changed. Go back and review it again before sending.']);
                }

                if ($this->targetsDate) {
                    $ids = array_map(intval(...), (array) ($data['event_ids'] ?? []));
                    sort($ids);

                    if ($review['selection'] !== ['date' => $data['date'] ?? '', 'time' => $data['time'] ?? '']
                        || $ids !== array_column($review['snapshot']['events'], 'id')) {
                        throw ValidationException::withMessages(['body' => 'The event selection has changed. Go back and review it again before sending.']);
                    }
                }

                if (! $this->targetsDate && array_column($review['snapshot']['events'], 'id') !== [$this->event()?->id]) {
                    throw ValidationException::withMessages(['body' => 'Review the message for this event before sending.']);
                }

                $batch = app(QueueEventTextMessages::class)->handle($this->author(), $reviewToken);
                $count = $batch->recipients()->count();
                Notification::make()->title('Text messages queued')
                    ->body("{$count} unique phone numbers. Sending does not change event status.")
                    ->success()->send();
            }));
    }

    public static function getDefaultName(): string
    {
        return 'sendTextMessage';
    }

    public function forEvent(Event|Closure $event): static
    {
        $this->textEvent = $event;

        return $this;
    }

    public function forDate(): static
    {
        $this->targetsDate = true;

        return $this->label('Text Events by Date');
    }

    private function canSend(): bool
    {
        $author = auth()->user();

        if (! $author instanceof User || ! $author->can('Send:TextMessage')) {
            return false;
        }

        return $this->targetsDate || ($this->event() instanceof Event && Gate::forUser($author)->allows('update', $this->event()));
    }

    private function event(): ?Event
    {
        return $this->evaluate($this->textEvent);
    }

    private function author(): User
    {
        $author = auth()->user();
        abort_unless($author instanceof User, 403);

        return $author;
    }

    /** @return array<int, string> */
    private function dateOptions(string $date, string $time): array
    {
        if (! $this->targetsDate || $date === '' || $time === '') {
            return [];
        }

        try {
            return app(EventTextRecipients::class)->forDate($this->author(), $date, $time)
                ->mapWithKeys(fn (Event $event): array => [
                    $event->id => $event->start_time->timezone(config('app.display_timezone'))->format('g:i A')
                        .' — '.$event->name.' ('.($event->isCancelled() ? 'Cancelled' : 'Scheduled').')',
                ])->all();
        } catch (ValidationException) {
            return [];
        }
    }

    private function refreshDateSelection(Get $get, Set $set): void
    {
        $set('event_ids', array_keys($this->dateOptions((string) $get('date'), (string) $get('time'))));
        $set('review_token', null);
    }

    private function withValidationNotice(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title('Review the text message')
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->warning()->send();

            throw $exception;
        }
    }
}
