<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Mail\SendRequiredProductPurchaseReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('products:send-purchase-reminders')]
#[Description('Send one-time reminders for outstanding required Product purchases')]
final class SendRequiredProductPurchaseRemindersCommand extends Command
{
    public function handle(SendRequiredProductPurchaseReminders $reminders): int
    {
        $result = $reminders->handle();
        $userLabel = Str::plural('user', $result['users_reminded']);
        $productLabel = Str::plural('product', $result['products_marked']);
        $this->info("Reminded {$result['users_reminded']} {$userLabel} about {$result['products_marked']} required {$productLabel}.");

        return self::SUCCESS;
    }
}
