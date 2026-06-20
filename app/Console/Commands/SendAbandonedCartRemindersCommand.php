<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendAbandonedCartReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cart:send-abandoned-reminders')]
#[Description('Send reminders for available cart items left for at least 24 hours')]
final class SendAbandonedCartRemindersCommand extends Command
{
    public function handle(SendAbandonedCartReminders $reminders): int
    {
        $result = $reminders->handle();

        $this->info("Reminded {$result['users_reminded']} user(s) about {$result['cart_items_marked']} cart item(s).");

        return self::SUCCESS;
    }
}
