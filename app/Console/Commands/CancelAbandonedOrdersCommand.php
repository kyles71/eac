<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Store\CancelOrder;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;

final class CancelAbandonedOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-abandoned {--hours=24 : Hours after which a pending order is considered abandoned}';

    protected $description = 'Cancel pending orders that have been abandoned beyond the configured threshold';

    public function handle(CancelOrder $cancelOrder): int
    {
        $hours = (int) $this->option('hours');

        $abandonedOrders = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('created_at', '<', now()->subHours($hours))
            ->get();

        $cancelled = 0;

        /** @var Order $order */
        foreach ($abandonedOrders as $order) {
            if ($cancelOrder->handle($order)) {
                $cancelled++;
            }
        }

        $this->info("Cancelled {$cancelled} abandoned order(s) out of {$abandonedOrders->count()} found.");

        return self::SUCCESS;
    }
}
