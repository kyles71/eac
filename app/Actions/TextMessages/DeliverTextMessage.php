<?php

declare(strict_types=1);

namespace App\Actions\TextMessages;

use App\Data\TextMessages\TextMessageSubmission;
use App\Enums\TextMessageStatus;
use App\Models\TextMessageRecipient;
use App\Services\TextMessages\EventTextRecipients;
use App\Services\TextMessages\TextMessageTransportManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class DeliverTextMessage
{
    public function __construct(private EventTextRecipients $recipients, private TextMessageTransportManager $transports) {}

    public function handle(int $recipientId): void
    {
        if (! config('text-messages.enabled')) {
            return;
        }

        $claim = (string) Str::uuid();
        $claimed = TextMessageRecipient::query()
            ->whereKey($recipientId)
            ->where('status', TextMessageStatus::Pending)
            ->where('available_at', '<=', now())
            ->where('attempts', '<', 3)
            ->update([
                'status' => TextMessageStatus::Sending,
                'claim_token' => $claim,
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $recipient = TextMessageRecipient::query()->with('batch')->findOrFail($recipientId);
        $batch = $recipient->batch;

        try {
            $transport = $this->transports->driver($batch->provider);
            $transport->validateConfiguration();
            $eligible = $this->recipients->stillEligible($batch->events, $recipient->phone, $recipient->sources);
        } catch (Throwable) {
            $this->finish($recipient, $claim, new TextMessageSubmission(TextMessageStatus::Failed, error: 'The recipient or provider configuration could not be checked. No request was sent.'));

            return;
        }

        if (! $eligible) {
            $this->finish($recipient, $claim, new TextMessageSubmission(TextMessageStatus::Skipped, error: 'The phone number no longer has an opted-in contact on the selected student rosters.'));

            return;
        }

        try {
            $result = $transport->send($recipient->phone, $batch->body, $batch->sender, $recipient->id);
        } catch (Throwable) {
            $result = new TextMessageSubmission(TextMessageStatus::Unknown, error: 'The provider request could not be confirmed. Review in the provider before resending.');
        }

        $this->finish($recipient, $claim, $result);
    }

    private function finish(TextMessageRecipient $recipient, string $claim, TextMessageSubmission $result): void
    {
        $status = $result->status;
        $error = $result->error;

        if ($status === TextMessageStatus::Pending && ($result->retryAfter === null || $recipient->attempts >= 3)) {
            $status = TextMessageStatus::Failed;
            $error = 'The provider rejected all three submission attempts. No message was accepted.';
        }

        $delay = max($result->retryAfter ?? 0, $recipient->attempts === 1 ? 30 : 120);
        TextMessageRecipient::query()->whereKey($recipient->id)
            ->where('status', TextMessageStatus::Sending)->where('claim_token', $claim)
            ->update([
                'status' => $status,
                'provider_receipt' => $result->receipt,
                'error' => $error,
                'available_at' => $status === TextMessageStatus::Pending ? now()->addSeconds($delay) : null,
                'finished_at' => $status === TextMessageStatus::Pending ? null : now(),
                'claim_token' => null,
            ]);
    }
}
