<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TextMessageStatus;
use App\Jobs\SendTextMessage;
use App\Models\TextMessageRecipient;
use Illuminate\Console\Command;

final class DispatchPendingTextMessages extends Command
{
    protected $signature = 'text-messages:recover';

    protected $description = 'Dispatch due text messages and flag interrupted submissions for review';

    public function handle(): int
    {
        $unknown = TextMessageRecipient::query()
            ->where('status', TextMessageStatus::Sending)
            ->where('started_at', '<=', now()->subMinutes(2))
            ->update([
                'status' => TextMessageStatus::Unknown,
                'finished_at' => now(),
                'claim_token' => null,
                'error' => 'The sending worker was interrupted. Review in the provider before resending.',
            ]);
        $queued = 0;

        if (config('text-messages.enabled')) {
            TextMessageRecipient::query()->where('status', TextMessageStatus::Pending)
                ->where('available_at', '<=', now())->where('attempts', '<', 3)
                ->chunkById(100, function ($recipients) use (&$queued): void {
                    foreach ($recipients as $recipient) {
                        SendTextMessage::dispatch($recipient->id)->afterCommit();
                        $queued++;
                    }
                });
        }

        $this->info("Queued {$queued} text message jobs; flagged {$unknown} interrupted submissions.");

        return self::SUCCESS;
    }
}
