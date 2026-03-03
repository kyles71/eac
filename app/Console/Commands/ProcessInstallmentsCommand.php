<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Store\ProcessInstallments;
use Illuminate\Console\Command;

final class ProcessInstallmentsCommand extends Command
{
    protected $signature = 'installments:process';

    protected $description = 'Process due and retryable payment plan installments';

    public function handle(ProcessInstallments $processInstallments): int
    {
        $result = $processInstallments->handle();

        $this->info('ProcessInstallments completed.');
        $this->table(
            ['Metric', 'Count'],
            collect($result)->map(fn ($value, $key) => [$key, $value])->values()->all(),
        );

        return self::SUCCESS;
    }
}
