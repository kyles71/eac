<?php

declare(strict_types=1);

namespace App\Services\TextMessages;

use App\Models\Event;
use App\Models\User;
use App\Support\TextMessages\MessageLength;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

final readonly class TextMessageReview
{
    public function __construct(
        private EventTextRecipients $recipients,
        private TextMessageTransportManager $transports,
    ) {}

    /** @param list<int|string> $eventIds
     * @param  array{date: string, time: string}|null  $selection
     */
    public function prepare(User $author, array $eventIds, string $body, string $submissionToken, ?array $selection = null): string
    {
        $this->transports->ensureEnabled();
        $provider = (string) config('text-messages.driver');
        $this->transports->driver($provider)->validateConfiguration();
        $sender = $this->transports->sender();
        Validator::make(['submission_token' => $submissionToken], ['submission_token' => ['required', 'uuid']])->validate();
        MessageLength::validate($body);
        $snapshot = $this->snapshot($author, $eventIds, $selection);

        if ($snapshot['recipients'] === []) {
            throw ValidationException::withMessages(['body' => 'No eligible emergency contacts were found. Review the selected events and contact preferences.']);
        }

        return Crypt::encryptString(json_encode([
            'author_id' => $author->id,
            'submission_token' => $submissionToken,
            'body' => $body,
            'provider' => $provider,
            'sender' => $sender,
            'selection' => $selection,
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function read(User $author, string $token): array
    {
        try {
            $review = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['body' => 'Review the message before sending.']);
        }

        if (! is_array($review) || ($review['author_id'] ?? null) !== $author->id) {
            throw ValidationException::withMessages(['body' => 'Review the message before sending.']);
        }

        return $review;
    }

    /** @param list<int|string> $eventIds
     * @param  array{date: string, time: string}|null  $selection
     * @return array<string, mixed>
     */
    public function snapshot(User $author, array $eventIds, ?array $selection): array
    {
        $events = $this->recipients->authorizedEvents($author, $eventIds);

        if ($selection !== null) {
            $matchingIds = $this->recipients->forDate($author, $selection['date'], $selection['time'])->modelKeys();

            if (array_diff($events->modelKeys(), $matchingIds) !== []) {
                throw ValidationException::withMessages(['event_ids' => 'An event no longer matches the selected date and time. Review your selection.']);
            }
        }

        return [
            'events' => $events->map(fn (Event $event): array => [
                'id' => $event->id,
                'name' => $event->name,
                'start_time' => $event->start_time?->toIso8601String(),
                'cancelled' => $event->isCancelled(),
            ])->all(),
            ...$this->recipients->resolve($events),
        ];
    }

    /** @param array<string, mixed> $value */
    public function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
