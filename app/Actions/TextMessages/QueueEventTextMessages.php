<?php

declare(strict_types=1);

namespace App\Actions\TextMessages;

use App\Jobs\SendTextMessage;
use App\Models\TextMessageBatch;
use App\Models\TextMessageRecipient;
use App\Models\User;
use App\Services\TextMessages\TextMessageReview;
use App\Services\TextMessages\TextMessageTransportManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class QueueEventTextMessages
{
    public function __construct(private TextMessageReview $reviews, private TextMessageTransportManager $transports) {}

    public function handle(User $author, string $reviewToken): TextMessageBatch
    {
        Gate::forUser($author)->authorize('Send:TextMessage');
        $review = $this->reviews->read($author, $reviewToken);
        $existing = TextMessageBatch::query()->where('submission_token', $review['submission_token'])->first();

        if ($existing !== null) {
            return $this->existingBatch($existing, $author, $review);
        }

        $this->transports->ensureEnabled();
        $this->transports->driver($review['provider'])->validateConfiguration();
        $snapshot = $this->reviews->snapshot($author, array_column($review['snapshot']['events'], 'id'), $review['selection']);

        if ($this->reviews->hash($snapshot) !== $this->reviews->hash($review['snapshot'])) {
            throw ValidationException::withMessages(['body' => 'Events or recipients have changed. Go back and review the message again before sending.']);
        }

        try {
            return DB::transaction(function () use ($review, $snapshot, $author): TextMessageBatch {
                $batch = TextMessageBatch::query()->create([
                    'submission_token' => $review['submission_token'],
                    'author_id' => $author->id,
                    'author_name' => $author->fullName,
                    'body' => $review['body'],
                    'provider' => $review['provider'],
                    'sender' => $review['sender'],
                    'events' => $snapshot['events'],
                    'selection' => $review['selection'],
                    'warnings' => $snapshot['warnings'],
                    'review_hash' => $this->reviews->hash($review),
                ]);

                foreach ($snapshot['recipients'] as $recipient) {
                    $batch->recipients()->create([
                        'phone' => $recipient['phone'],
                        'sources' => $recipient['sources'],
                        'available_at' => now(),
                    ]);
                }

                DB::afterCommit(function () use ($batch): void {
                    $batch->recipients()->each(function (TextMessageRecipient $recipient): void {
                        rescue(fn () => Bus::dispatch(new SendTextMessage($recipient->id)), report: true);
                    });
                });

                return $batch;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = TextMessageBatch::query()->where('submission_token', $review['submission_token'])->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->existingBatch($existing, $author, $review);
        }
    }

    /** @param array<string, mixed> $review */
    private function existingBatch(TextMessageBatch $batch, User $author, array $review): TextMessageBatch
    {
        if ($batch->author_id !== $author->id || $batch->review_hash !== $this->reviews->hash($review)) {
            throw ValidationException::withMessages(['body' => 'This composer has already sent a different message. Open a new composer to send another message.']);
        }

        $this->reviews->snapshot($author, array_column($batch->events, 'id'), null);

        return $batch;
    }
}
