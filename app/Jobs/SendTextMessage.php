<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\TextMessages\DeliverTextMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendTextMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $recipientId) {}

    public function handle(DeliverTextMessage $delivery): void
    {
        $delivery->handle($this->recipientId);
    }
}
